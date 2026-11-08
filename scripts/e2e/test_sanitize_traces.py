import contextlib
import importlib.util
import io
import os
import tempfile
import unittest
import zipfile
from pathlib import Path


SCRIPT = Path(__file__).with_name("sanitize_traces.py")
SPEC = importlib.util.spec_from_file_location("sanitize_traces", SCRIPT)
sanitize_traces = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(sanitize_traces)


class SanitizeTracesTests(unittest.TestCase):
    def test_sanitizes_e2e_control_header_in_structured_and_raw_trace_text(self):
        marker = "E2E_SECRET_MARKER_control_token"
        source = (
            '{"headers":[{"name":"X-E2E-Control","value":"' + marker + '"}]}\n'
            'X-E2E-Control: ' + marker + '\n'
        )

        sanitized, count = sanitize_traces.sanitize_text(source)

        self.assertGreaterEqual(count, 2)
        self.assertNotIn(marker, sanitized)
        self.assertGreaterEqual(sanitized.count("[REDACTED]"), 2)
        resanitized, second_count = sanitize_traces.sanitize_text(sanitized)
        self.assertEqual(second_count, 0)
        self.assertEqual(resanitized, sanitized)

    def test_sanitizes_trace_text_in_zip_and_preserves_other_evidence(self):
        marker = "E2E_SECRET_MARKER_cookie_value"
        with tempfile.TemporaryDirectory() as temp_dir:
            archive = Path(temp_dir) / "trace.zip"
            screenshot = b"\x89PNG\r\n\x1a\nSCREENSHOT_BYTES"
            with zipfile.ZipFile(archive, "w") as bundle:
                bundle.writestr("trace.network", '{"method":"GET","headers":[{"name":"cookie","value":"' + marker + '"}]}\n')
                bundle.writestr("trace.trace", '{"type":"action","name":"click Save"}\n')
                bundle.writestr("resources/screenshot.png", screenshot)

            count = sanitize_traces.sanitize_path(archive)

            self.assertGreaterEqual(count, 1)
            with zipfile.ZipFile(archive) as bundle:
                network = bundle.read("trace.network").decode("utf-8")
                actions = bundle.read("trace.trace").decode("utf-8")
                self.assertNotIn(marker, network)
                self.assertIn("click Save", actions)
                self.assertEqual(bundle.read("resources/screenshot.png"), screenshot)
            self.assertEqual(sanitize_traces.check_path(archive), 0)

    def test_check_mode_reports_path_and_count_without_echoing_secret(self):
        marker = "E2E_SECRET_MARKER_auth_value"
        with tempfile.TemporaryDirectory() as temp_dir:
            trace = Path(temp_dir) / "trace.network"
            trace.write_text('{"headers":{"authorization":"Bearer ' + marker + '"}}\n', encoding="utf-8")
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                count = sanitize_traces.check_path(trace)

            self.assertGreaterEqual(count, 1)
            self.assertIn("trace.network", stdout.getvalue())
            self.assertNotIn(marker, stdout.getvalue())

    def test_cli_refuses_paths_outside_e2e_artifact_roots(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            stderr = io.StringIO()
            with contextlib.redirect_stderr(stderr):
                result = sanitize_traces.main(["--sanitize", temp_dir])

            self.assertEqual(result, 2)
            self.assertIn("outside the E2E artifact allowlist", stderr.getvalue())

    def test_check_mode_accepts_an_empty_allowed_artifact_directory(self):
        allowed_root = sanitize_traces.REPOSITORY_ROOT / "docs/e2e/runs/assets"
        with tempfile.TemporaryDirectory(dir=allowed_root) as temp_dir:
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                result = sanitize_traces.main(["--check", temp_dir])

        self.assertEqual(result, 0)
        self.assertIn("no supported trace artifacts", stdout.getvalue())


if __name__ == "__main__":
    unittest.main()
