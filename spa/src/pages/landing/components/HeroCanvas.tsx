/**
 * HeroCanvas — a precision part, drawn.
 *
 * A revolved wireframe of an automotive oil filler cap (seal flange → grip
 * skirt → top deck → hollow bore), rotating slowly inside the hero's drawing
 * frame. Rendered as clean ink lines on the page with crisp espresso edges, so
 * it reads like a turning CAD model on a blueprint — directly connected to
 * what Ogami makes, no decoration.
 *
 * Defensive by construction:
 *   • No WebGL / reduced-motion → renders nothing; the SVG PartBlueprint shows.
 *   • Mobile / low DPR           → capped pixel ratio, lighter line load.
 *   • Tab hidden / off-screen    → render loop pauses.
 *   • Unmount / context loss      → geometry, material, renderer disposed.
 *   • Drag-to-rotate              → fine pointer only; inertial spin + decay.
 *   • Scroll-velocity tint        → reads window.lenis.velocity each frame.
 */

import { useEffect, useRef } from 'react';
import {
  Color,
  Group,
  LatheGeometry,
  LineBasicMaterial,
  LineSegments,
  EdgesGeometry,
  WireframeGeometry,
  PerspectiveCamera,
  Scene,
  Vector2,
  WebGLRenderer,
  MathUtils,
} from 'three';

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

/**
 * Half-profile of an automotive oil filler cap, revolved around the Y axis.
 *
 * Profile includes a seal flange at the base, a grip skirt, a radiused top
 * deck, and a hollow internal bore. Points run bottom→top in the X (radius)
 * / Y (height) plane.
 */
function partProfile(): Vector2[] {
  return [
    new Vector2(0.0, -1.6), // center bottom
    new Vector2(1.5, -1.6), // flange outer bottom
    new Vector2(1.5, -1.45), // flange up
    new Vector2(1.45, -1.4), // flange top outer
    new Vector2(1.25, -1.35), // transition to skirt
    new Vector2(1.2, -1.2), // skirt lower
    new Vector2(1.2, 0.6), // skirt upper
    new Vector2(1.25, 0.8), // top corner radius
    new Vector2(1.15, 0.85), // top outer
    new Vector2(0.25, 0.85), // top deck
    new Vector2(0.2, 0.8), // inner top corner
    new Vector2(0.2, 0.0), // inner wall
    new Vector2(0.25, -1.0), // inner lower
    new Vector2(0.0, -1.0), // center inner bottom
  ];
}

export function HeroCanvas() {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced || !supportsWebGL()) return;

    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    const isFinePntr = window.matchMedia('(pointer:fine)').matches;
    const pixelRatio = Math.min(window.devicePixelRatio || 1, isMobile ? 1.5 : 1.75);

    // ── Scene & camera ──────────────────────────────────────────────
    const scene = new Scene();
    const camera = new PerspectiveCamera(40, 1, 0.1, 100);
    camera.position.set(0, 0.2, 7.8);
    camera.lookAt(0, 0, 0);

    // ── Part geometry (revolved profile) ────────────────────────────
    const segments = isMobile ? 64 : 120;
    const lathe = new LatheGeometry(partProfile(), segments);

    const group = new Group();

    // Surface wireframe — faint ink mesh.
    const wire = new WireframeGeometry(lathe);
    const wireMat = new LineBasicMaterial({
      color: new Color('#18181b'),
      transparent: true,
      opacity: isMobile ? 0.16 : 0.2,
    });
    const wireMesh = new LineSegments(wire, wireMat);

    // Feature edges — crisp espresso outline of the silhouette/creases.
    const edges = new EdgesGeometry(lathe, 22);
    const edgeMat = new LineBasicMaterial({
      color: new Color('#1c1917'),
      transparent: true,
      opacity: 0.85,
    });
    const edgeMesh = new LineSegments(edges, edgeMat);

    group.add(wireMesh);
    group.add(edgeMesh);
    group.rotation.x = 0.32;
    // Profile spans Y -1.6 → 0.85 (centroid ≈ -0.375), so the revolved mesh
    // sits low against a camera that looks at the origin. Lift it so the part
    // is vertically centered in its frame.
    group.position.y = 0.375;
    scene.add(group);

    // ── Renderer ────────────────────────────────────────────────────
    let renderer: WebGLRenderer;
    try {
      renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    } catch {
      lathe.dispose();
      wire.dispose();
      edges.dispose();
      wireMat.dispose();
      edgeMat.dispose();
      return;
    }
    renderer.setPixelRatio(pixelRatio);
    renderer.setClearColor(0x000000, 0); // transparent → shows the paper
    const canvas = renderer.domElement;
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.display = 'block';
    canvas.style.opacity = '0';
    canvas.style.transition = 'opacity 700ms ease-out';
    // Enable pointer events for drag; we use pointer capture so scroll isn't blocked.
    canvas.style.pointerEvents = isFinePntr ? 'auto' : 'none';
    container.appendChild(canvas);

    // Fade the WebGL canvas in once the first frame has rendered so the
    // static PartBlueprint underneath is never exposed as a blank flash.
    requestAnimationFrame(() => {
      canvas.style.opacity = '1';
    });

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

    // ── Pointer parallax (gentle tilt) ──────────────────────────────
    const targetTilt = { x: 0, y: 0 };
    function onPointerMove(e: PointerEvent) {
      if (isDragging) return; // tilt deferred during drag
      const r = container!.getBoundingClientRect();
      targetTilt.y = ((e.clientX - (r.left + r.width / 2)) / r.width) * 0.5;
      targetTilt.x = ((e.clientY - (r.top + r.height / 2)) / r.height) * 0.3;
    }
    window.addEventListener('pointermove', onPointerMove, { passive: true });

    // ── Drag-to-rotate (fine pointer only) ──────────────────────────
    let isDragging = false;
    let dragOffsetY = 0; // accumulated manual rotation around Y
    let spinVelocity = 0; // px/frame, decays after pointerup
    let lastDragX = 0;

    function onCanvasPointerDown(e: PointerEvent) {
      if (!isFinePntr) return;
      isDragging = true;
      lastDragX = e.clientX;
      spinVelocity = 0;
      canvas.setPointerCapture(e.pointerId);
      e.stopPropagation(); // don't leak to page
    }

    function onWindowPointerMove(e: PointerEvent) {
      if (!isDragging) return;
      const dx = e.clientX - lastDragX;
      lastDragX = e.clientX;
      // Scale: 1 full drag width ≈ 2π rotation
      const rotDelta = (dx / (container!.clientWidth || 1)) * Math.PI * 2.2;
      dragOffsetY += rotDelta;
      spinVelocity = rotDelta; // raw per-frame velocity for inertia
    }

    function onWindowPointerUp() {
      if (!isDragging) return;
      isDragging = false;
    }

    if (isFinePntr) {
      canvas.addEventListener('pointerdown', onCanvasPointerDown);
      window.addEventListener('pointermove', onWindowPointerMove, { passive: true });
      window.addEventListener('pointerup', onWindowPointerUp);
      window.addEventListener('pointercancel', onWindowPointerUp);
    }

    // ── Visibility / in-view gating ─────────────────────────────────
    let inView = true;
    const io = new IntersectionObserver(
      ([entry]) => {
        inView = entry.isIntersecting;
        if (inView && !rafId) loop(lastTime);
      },
      { threshold: 0 },
    );
    io.observe(container);

    function onVisibility() {
      if (document.hidden) stop();
      else if (inView) loop(lastTime);
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
    let lastTime = start;
    // Turntable base angle (advances each frame from time)
    let turntableAngle = 0;

    function loop(now: number) {
      lastTime = now;
      if (document.hidden || !inView) {
        rafId = 0;
        return;
      }
      const t = (now - start) / 1000;

      // Steady turntable contribution (radians/s)
      turntableAngle = t * 0.5;

      // Scroll-velocity tint — tiny nudge proportional to Lenis scroll speed
      const lVelocity = (window as unknown as { lenis?: { velocity?: number } }).lenis?.velocity ?? 0;
      const scrollSpin = lVelocity * 0.0015;

      // Inertial drag spin: decay toward zero, then ease drag offset toward 0
      if (!isDragging) {
        spinVelocity *= 0.88; // friction
        dragOffsetY += spinVelocity;
        // Slowly recover drag offset back to 0 (gentle "snap back to turntable")
        dragOffsetY *= 0.985;
      }

      group.rotation.y = turntableAngle + dragOffsetY + scrollSpin;
      group.rotation.x = MathUtils.lerp(group.rotation.x, 0.32 + targetTilt.x, 0.05);
      group.rotation.z = MathUtils.lerp(group.rotation.z, targetTilt.y * 0.3, 0.05);

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
      lathe.dispose();
      wire.dispose();
      edges.dispose();
      wireMat.dispose();
      edgeMat.dispose();
      renderer.dispose();
      if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
    };
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
