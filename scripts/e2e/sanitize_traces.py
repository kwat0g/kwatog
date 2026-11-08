#!/usr/bin/env python3
"""Check or redact credentials in Playwright trace artifacts.

This utility is for disposable E2E traces only. It never prints artifact contents.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import stat
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any


REDACTED = "[REDACTED]"
TRACE_EXTENSIONS = {".zip", ".trace", ".network", ".json", ".jsonl"}
TEXT_EXTENSIONS = {".trace", ".network", ".json", ".jsonl", ".txt", ".log", ".html"}
SENSITIVE_NAMES = {
    "cookie",
    "setcookie",
    "authorization",
    "proxyauthorization",
    "password",
    "passwd",
    "secret",
    "clientsecret",
    "token",
    "accesstoken",
    "refreshtoken",
    "idtoken",
    "apikey",
    "xsrf",
    "xsrftoken",
    "csrf",
    "csrftoken",
    "session",
    "sessionid",
    "laravelsession",
    "xe2econtrol",
}

LARAVEL_COOKIE = re.compile(r"eyJpdiI6[A-Za-z0-9+/=_-]{16,}")
BEARER_VALUE = re.compile(r"(?i)(\bBearer\s+)[A-Za-z0-9._~+/-]+=*")
HEADER_LINE = re.compile(
    r"(?im)(\b(?:cookie|set-cookie|authorization|proxy-authorization|x-xsrf-token|x-csrf-token|x-e2e-control)\s*:\s*)(?!\s*\[REDACTED\])[^\r\n\"']+"
)
COOKIE_ASSIGNMENT = re.compile(
    r"(?i)(\b(?:laravel_session|xsrf-token|csrf-token|sessionid|session_id)\s*=\s*)(?!\[REDACTED\])[^;\s,\"']+"
)
SENSITIVE_ASSIGNMENT = re.compile(
    r"(?i)([\"']?(?:authorization|cookie|set-cookie|password|passwd|secret|client_secret|access_token|refresh_token|id_token|api_key|csrf_token|xsrf_token|session_id|x[-_]?e2e[-_]?control)[\"']?\s*[:=]\s*[\"']?)(?!\[REDACTED\])[^\s,;\"'}]+"
)
REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
ALLOWED_ROOTS = (
    REPOSITORY_ROOT / "docs/e2e/runs",
    REPOSITORY_ROOT / "spa/test-results/process",
    REPOSITORY_ROOT / "spa/playwright-report/process",
)


def _sensitive_name(value: Any) -> bool:
    normalized = re.sub(r"[^a-z0-9]", "", str(value).lower())
    return normalized in SENSITIVE_NAMES or any(
        word in normalized for word in ("cookie", "authorization", "password", "secret", "token", "csrf", "xsrf", "session")
    )


def _redact_json(value: Any) -> tuple[Any, int]:
    count = 0
    if isinstance(value, dict):
        header_name = value.get("name", "")
        header_is_sensitive = _sensitive_name(header_name)
        result: dict[str, Any] = {}
        for key, item in value.items():
            if _sensitive_name(key) or (header_is_sensitive and str(key).lower() == "value"):
                result[key] = item
                if item != REDACTED:
                    result[key] = REDACTED
                    count += 1
            else:
                result[key], changed = _redact_json(item)
                count += changed
        return result, count
    if isinstance(value, list):
        result = []
        for item in value:
            redacted, changed = _redact_json(item)
            result.append(redacted)
            count += changed
        return result, count
    return value, count


def sanitize_text(text: str) -> tuple[str, int]:
    """Redact sensitive structured fields and recognizable credential strings."""
    output_lines: list[str] = []
    structured_count = 0
    for line in text.splitlines(keepends=True):
        body = line.rstrip("\r\n")
        ending = line[len(body) :]
        try:
            parsed = json.loads(body)
        except (json.JSONDecodeError, TypeError):
            output_lines.append(line)
            continue
        redacted, changed = _redact_json(parsed)
        structured_count += changed
        output_lines.append(json.dumps(redacted, ensure_ascii=False, separators=(",", ":")) + ending)

    output = "".join(output_lines)
    regex_count = 0
    for pattern in (HEADER_LINE, COOKIE_ASSIGNMENT, BEARER_VALUE, SENSITIVE_ASSIGNMENT, LARAVEL_COOKIE):
        output, changed = pattern.subn(lambda match: (match.group(1) if match.lastindex else "") + REDACTED, output)
        regex_count += changed
    return output, structured_count + regex_count


def _sanitize_bytes(data: bytes) -> tuple[bytes, int]:
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError:
        return data, 0
    sanitized, count = sanitize_text(text)
    return sanitized.encode("utf-8"), count


def _sanitize_archive(path: Path, write_changes: bool) -> int:
    total = 0
    changed_entries: dict[str, bytes] = {}
    with zipfile.ZipFile(path, "r") as source:
        for info in source.infolist():
            if info.is_dir() or Path(info.filename).suffix.lower() not in TEXT_EXTENSIONS:
                continue
            data = source.read(info.filename)
            clean, count = _sanitize_bytes(data)
            if count:
                total += count
                changed_entries[info.filename] = clean
        if not write_changes or not changed_entries:
            return total

        original_mode = stat.S_IMODE(path.stat().st_mode)
        fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".sanitizing", dir=path.parent)
        os.close(fd)
        temp_path = Path(temp_name)
        try:
            with zipfile.ZipFile(temp_path, "w") as target:
                target.comment = source.comment
                for info in source.infolist():
                    if info.is_dir():
                        target.writestr(info, b"")
                    else:
                        target.writestr(info, changed_entries.get(info.filename, source.read(info.filename)))
            os.chmod(temp_path, original_mode)
            os.replace(temp_path, path)
        finally:
            if temp_path.exists():
                temp_path.unlink()
    return total


def _sanitize_loose_file(path: Path, write_changes: bool) -> int:
    original = path.read_bytes()
    cleaned, count = _sanitize_bytes(original)
    if write_changes and count:
        mode = stat.S_IMODE(path.stat().st_mode)
        fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".sanitizing", dir=path.parent)
        os.close(fd)
        temp_path = Path(temp_name)
        try:
            temp_path.write_bytes(cleaned)
            os.chmod(temp_path, mode)
            os.replace(temp_path, path)
        finally:
            if temp_path.exists():
                temp_path.unlink()
    return count


def _process(path: Path, write_changes: bool) -> int:
    if path.suffix.lower() == ".zip":
        return _sanitize_archive(path, write_changes)
    return _sanitize_loose_file(path, write_changes)


def sanitize_path(path: Path | str) -> int:
    """Redact sensitive values in one supported trace artifact in place."""
    target = Path(path)
    if target.suffix.lower() not in TRACE_EXTENSIONS:
        raise ValueError("unsupported trace artifact extension")
    return _process(target, write_changes=True)


def check_path(path: Path | str) -> int:
    """Print a path and redaction count; return count without exposing values."""
    target = Path(path)
    if target.suffix.lower() not in TRACE_EXTENSIONS:
        raise ValueError("unsupported trace artifact extension")
    count = _process(target, write_changes=False)
    print(f"{target}: {'credential-like values found' if count else 'clean'} (count={count})")
    return count


def _targets(path: Path) -> list[Path]:
    if path.is_symlink():
        return []
    if path.is_file():
        return [path] if path.suffix.lower() in TRACE_EXTENSIONS else []
    if not path.is_dir():
        return []
    return sorted(
        item
        for item in path.rglob("*")
        if not item.is_symlink() and item.is_file() and item.suffix.lower() in TRACE_EXTENSIONS
    )


def _is_allowed_location(path: Path) -> bool:
    resolved = path.resolve()
    return any(resolved == root.resolve() or resolved.is_relative_to(root.resolve()) for root in ALLOWED_ROOTS)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true", help="scan without modifying files; exit 1 if credentials are found")
    mode.add_argument("--sanitize", action="store_true", help="redact credentials in supported trace artifacts in place")
    parser.add_argument("path", type=Path, help="one trace artifact or a directory containing trace artifacts")
    args = parser.parse_args(argv)

    if not _is_allowed_location(args.path):
        print(f"ERROR {args.path}: path is outside the E2E artifact allowlist", file=sys.stderr)
        return 2
    targets = _targets(args.path)
    if not targets:
        if args.path.is_dir():
            print(f"{args.path}: no supported trace artifacts (count=0)")
            return 0
        print(f"ERROR {args.path}: no supported trace artifacts found", file=sys.stderr)
        return 2
    found = 0
    try:
        for target in targets:
            if args.sanitize:
                count = sanitize_path(target)
                print(f"{target}: {'redacted' if count else 'clean'} (count={count})")
            else:
                count = check_path(target)
            found += count
    except (OSError, zipfile.BadZipFile, ValueError) as error:
        # Deliberately omit exception text; malformed inputs can contain sensitive values.
        print(f"ERROR {args.path}: processing failed ({type(error).__name__})", file=sys.stderr)
        return 2
    return 1 if args.check and found else 0


if __name__ == "__main__":
    raise SystemExit(main())
