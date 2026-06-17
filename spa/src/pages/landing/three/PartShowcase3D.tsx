/**
 * PartShowcase3D — an interactive wireframe parts viewer.
 *
 * A single, long-lived WebGL context turns the selected {@link PartDef} as clean
 * ink lines on the page. Sections can pull apart into an engineering exploded
 * view; parts cross-fade when switched (the renderer is never recreated, so the
 * browser's WebGL-context budget is never spent down). Drag to rotate, release
 * for inertia, and the turntable resumes; scroll speed adds a faint nudge.
 *
 * Defensive by construction (mirrors HeroCanvas):
 *   • reduced-motion / no-WebGL → renders nothing (the section shows the static
 *     ProfileSilhouette instead).
 *   • off-screen / tab hidden    → render loop pauses.
 *   • part swap / unmount        → every geometry + material is disposed.
 */

import { useEffect, useRef } from 'react';
import {
  Color,
  EdgesGeometry,
  Group,
  LatheGeometry,
  LineBasicMaterial,
  LineSegments,
  MathUtils,
  PerspectiveCamera,
  Scene,
  WebGLRenderer,
  WireframeGeometry,
} from 'three';
import type { PartDef } from './parts';
import { reduceMotion } from '../motion';

function supportsWebGL(): boolean {
  try {
    const canvas = document.createElement('canvas');
    return !!(
      window.WebGLRenderingContext &&
      (canvas.getContext('webgl') || canvas.getContext('experimental-webgl'))
    );
  } catch {
    return false;
  }
}

interface PartShowcase3DProps {
  part: PartDef;
  exploded: boolean;
}

const EXPLODE_DIST = 0.66;
// The model is framed a touch small when assembled, and pulled in further while
// exploded so the separated sections always stay inside the drawing frame.
const SCALE_ASSEMBLED = 0.9;
const SCALE_EXPLODED = 0.72;

export function PartShowcase3D({ part, exploded }: PartShowcase3DProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const explodedRef = useRef(exploded);
  const buildPartRef = useRef<((p: PartDef) => void) | null>(null);

  useEffect(() => {
    explodedRef.current = exploded;
  }, [exploded]);

  // Swap the displayed part whenever the prop changes (engine effect, declared
  // below, installs buildPartRef before this runs on mount).
  useEffect(() => {
    buildPartRef.current?.(part);
  }, [part]);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    if (reduceMotion() || !supportsWebGL()) return;

    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    const isFinePntr = window.matchMedia('(pointer: fine)').matches;
    const pixelRatio = Math.min(window.devicePixelRatio || 1, isMobile ? 1.5 : 1.75);
    const segments = isMobile ? 72 : 130;

    // Ink colour from the live theme so the part reads on light or dark paper.
    const inkStr =
      getComputedStyle(container).getPropertyValue('--landing-ink').trim() || '#1c1917';
    const ink = new Color(inkStr);

    // ── Scene & camera ──────────────────────────────────────────────
    const scene = new Scene();
    const camera = new PerspectiveCamera(38, 1, 0.1, 100);
    camera.position.set(0, 0.15, 8.6);
    camera.lookAt(0, 0, 0);

    let renderer: WebGLRenderer;
    try {
      renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    } catch {
      return;
    }
    renderer.setPixelRatio(pixelRatio);
    renderer.setClearColor(0x000000, 0);
    const canvas = renderer.domElement;
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.display = 'block';
    canvas.style.opacity = '0';
    canvas.style.transition = 'opacity 600ms ease-out';
    canvas.style.pointerEvents = isFinePntr ? 'auto' : 'none';
    canvas.style.touchAction = 'pan-y';
    container.appendChild(canvas);
    const fadeRaf = requestAnimationFrame(() => {
      canvas.style.opacity = '1';
    });

    // Deferred disposals of swapped-out parts — tracked so a fast unmount can
    // clear them instead of firing into a torn-down renderer.
    const pendingDisposes: number[] = [];

    // ── Part group (rebuilt on swap) ────────────────────────────────
    type Tracked = {
      group: Group;
      geoms: { dispose(): void }[];
      mats: { mat: LineBasicMaterial; target: number }[];
      fadeStart: number;
    };
    let current: Tracked | null = null;

    function disposeTracked(t: Tracked) {
      scene.remove(t.group);
      t.geoms.forEach((g) => g.dispose());
      t.mats.forEach((m) => m.mat.dispose());
    }

    function buildPart(p: PartDef) {
      const previous = current;

      const group = new Group();
      const geoms: { dispose(): void }[] = [];
      const mats: { mat: LineBasicMaterial; target: number }[] = [];

      p.sections.forEach((sec) => {
        const lathe = new LatheGeometry(sec.profile, segments);
        const wireGeo = new WireframeGeometry(lathe);
        const edgeGeo = new EdgesGeometry(lathe, p.edgeAngle);
        geoms.push(lathe, wireGeo, edgeGeo);

        const wireMat = new LineBasicMaterial({ color: ink, transparent: true, opacity: 0 });
        const edgeMat = new LineBasicMaterial({ color: ink, transparent: true, opacity: 0 });
        mats.push(
          { mat: wireMat, target: isMobile ? 0.14 : 0.18 },
          { mat: edgeMat, target: 0.9 },
        );

        const sectionGroup = new Group();
        sectionGroup.add(new LineSegments(wireGeo, wireMat));
        sectionGroup.add(new LineSegments(edgeGeo, edgeMat));
        sectionGroup.userData.explode = sec.explode;
        group.add(sectionGroup);
      });

      group.scale.setScalar(SCALE_ASSEMBLED);
      scene.add(group);
      current = { group, geoms, mats, fadeStart: performance.now() };

      // Dispose the outgoing part once the incoming one has faded in.
      if (previous) {
        pendingDisposes.push(window.setTimeout(() => disposeTracked(previous), 650));
      }
    }
    buildPartRef.current = buildPart;
    buildPart(part);

    // ── Sizing ──────────────────────────────────────────────────────
    function resize() {
      const w = container!.clientWidth || 1;
      const h = container!.clientHeight || 1;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
    }
    resize();
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container);

    // ── Pointer parallax + drag-to-rotate ───────────────────────────
    const targetTilt = { x: 0, y: 0 };
    function onPointerMove(e: PointerEvent) {
      if (isDragging) return;
      const r = container!.getBoundingClientRect();
      targetTilt.y = ((e.clientX - (r.left + r.width / 2)) / r.width) * 0.45;
      targetTilt.x = ((e.clientY - (r.top + r.height / 2)) / r.height) * 0.28;
    }
    window.addEventListener('pointermove', onPointerMove, { passive: true });

    let isDragging = false;
    let dragOffsetY = 0;
    let spinVelocity = 0;
    let lastDragX = 0;

    function onCanvasPointerDown(e: PointerEvent) {
      if (!isFinePntr) return;
      isDragging = true;
      lastDragX = e.clientX;
      spinVelocity = 0;
      canvas.setPointerCapture(e.pointerId);
    }
    function onWindowPointerMove(e: PointerEvent) {
      if (!isDragging) return;
      const dx = e.clientX - lastDragX;
      lastDragX = e.clientX;
      const rotDelta = (dx / (container!.clientWidth || 1)) * Math.PI * 2.2;
      dragOffsetY += rotDelta;
      spinVelocity = rotDelta;
    }
    function onWindowPointerUp() {
      isDragging = false;
    }
    if (isFinePntr) {
      canvas.addEventListener('pointerdown', onCanvasPointerDown);
      window.addEventListener('pointermove', onWindowPointerMove, { passive: true });
      window.addEventListener('pointerup', onWindowPointerUp);
      window.addEventListener('pointercancel', onWindowPointerUp);
    }

    // ── Visibility / in-view gating ─────────────────────────────────
    // Start stopped — the observer kicks the loop the moment the frame enters
    // view, so an off-screen mount renders nothing.
    let inView = false;
    const io = new IntersectionObserver(
      ([entry]) => {
        inView = entry.isIntersecting;
        if (inView && !rafId) loop();
      },
      { threshold: 0 },
    );
    io.observe(container);

    function onVisibility() {
      if (document.hidden) stop();
      else if (inView && !rafId) loop();
    }
    document.addEventListener('visibilitychange', onVisibility);

    function onContextLost(e: Event) {
      e.preventDefault();
      stop();
    }
    canvas.addEventListener('webglcontextlost', onContextLost);

    // ── Animation loop ──────────────────────────────────────────────
    const start = performance.now();
    let rafId = 0;
    let baseTiltX = 0.3;

    function loop() {
      if (document.hidden || !inView) {
        rafId = 0;
        return;
      }
      const now = performance.now();
      const t = (now - start) / 1000;

      const lVelocity = (window as unknown as { lenis?: { velocity?: number } }).lenis?.velocity ?? 0;
      const scrollSpin = lVelocity * 0.0012;

      if (!isDragging) {
        spinVelocity *= 0.9;
        dragOffsetY += spinVelocity;
        dragOffsetY *= 0.99;
      }

      if (current) {
        const g = current.group;
        g.rotation.y = t * 0.42 + dragOffsetY + scrollSpin;
        baseTiltX = MathUtils.lerp(baseTiltX, 0.3 + targetTilt.x, 0.05);
        g.rotation.x = baseTiltX;
        g.rotation.z = MathUtils.lerp(g.rotation.z, targetTilt.y * 0.28, 0.05);

        // Exploded view — ease each section along the axis, and shrink the whole
        // group while exploded so the separated stack stays inside the frame.
        const explodeNow = explodedRef.current;
        g.children.forEach((child) => {
          const off = (child.userData.explode as number) ?? 0;
          const targetY = explodeNow ? off * EXPLODE_DIST : 0;
          child.position.y = MathUtils.lerp(child.position.y, targetY, 0.12);
        });
        const targetScale = explodeNow ? SCALE_EXPLODED : SCALE_ASSEMBLED;
        const s = MathUtils.lerp(g.scale.x, targetScale, 0.1);
        g.scale.setScalar(s);

        // Fade-in materials of the freshly built part.
        const fade = Math.min((now - current.fadeStart) / 500, 1);
        current.mats.forEach((m) => {
          m.mat.opacity = m.target * fade;
        });
      }

      renderer.render(scene, camera);
      rafId = requestAnimationFrame(loop);
    }
    function stop() {
      if (rafId) cancelAnimationFrame(rafId);
      rafId = 0;
    }
    rafId = requestAnimationFrame(loop);

    // ── Teardown ────────────────────────────────────────────────────
    return () => {
      stop();
      cancelAnimationFrame(fadeRaf);
      pendingDisposes.forEach(clearTimeout);
      buildPartRef.current = null;
      resizeObserver.disconnect();
      io.disconnect();
      window.removeEventListener('pointermove', onPointerMove);
      document.removeEventListener('visibilitychange', onVisibility);
      canvas.removeEventListener('webglcontextlost', onContextLost);
      if (isFinePntr) {
        canvas.removeEventListener('pointerdown', onCanvasPointerDown);
        window.removeEventListener('pointermove', onWindowPointerMove);
        window.removeEventListener('pointerup', onWindowPointerUp);
        window.removeEventListener('pointercancel', onWindowPointerUp);
      }
      if (current) disposeTracked(current);
      renderer.dispose();
      if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
    };
    // The part swap is handled by the dedicated effect above; this engine is
    // built once for the component's lifetime.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div
      ref={containerRef}
      data-cursor-lock
      aria-hidden="true"
      className="absolute inset-0 h-full w-full"
    />
  );
}
