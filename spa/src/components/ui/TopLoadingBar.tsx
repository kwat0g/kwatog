import { useEffect, useState } from 'react';

export function TopLoadingBar() {
  const [width, setWidth] = useState(0);

  useEffect(() => {
    const t1 = window.setTimeout(() => setWidth(40), 50);
    const t2 = window.setTimeout(() => setWidth(70), 300);
    const t3 = window.setTimeout(() => setWidth(85), 800);
    return () => { clearTimeout(t1); clearTimeout(t2); clearTimeout(t3); };
  }, []);

  return (
    <div className="fixed top-0 left-0 right-0 z-[60] h-[2px]">
      <div
        className="h-full bg-accent transition-[width] ease-out"
        style={{ width: `${width}%`, transitionDuration: width <= 40 ? '200ms' : '600ms' }}
      />
    </div>
  );
}
