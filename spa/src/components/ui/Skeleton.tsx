import { type CSSProperties } from 'react';
import { cn } from '@/lib/cn';

interface BlockProps {
 className?: string;
 style?: CSSProperties;
}
/**
 * Core dynamic skeleton block. Uses an animated metallic shimmer sweep
 * gradient instead of a static pulsing gray block for a modern, fluid UX.
 */
export function SkeletonBlock({ className, style }: BlockProps) {
 return (
 <div
 className={cn(
 'rounded-md bg-gradient-to-r from-elevated via-surface/80 to-elevated bg-[length:200%_100%] animate-pulse animate-shimmer',
 className,
 )}
 style={style}
 />
 );
}

interface SkeletonTableProps {
 columns?: number;
 rows?: number;
 className?: string;
}

/** Realistic dynamic table skeleton with header row and staggered column widths. */
export function SkeletonTable({ columns = 6, rows = 8, className }: SkeletonTableProps) {
 return (
 <div className={cn('border border-default rounded-md overflow-hidden bg-canvas', className)}>
 <div className="h-8 border-b border-default bg-subtle/50 flex items-center px-3 gap-4">
 {Array.from({ length: columns }).map((_, i) => (
 <SkeletonBlock
 key={i}
 className="h-2.5 rounded-sm"
 style={{ width: i === 0 ? '60px' : i === columns - 1 ? '70px' : '90px' }}
 />
 ))}
 </div>
 {Array.from({ length: rows }).map((_, i) => (
 <div key={i} className="h-8 border-b border-subtle flex items-center px-3 gap-4 hover:bg-subtle/30">
 {Array.from({ length: columns }).map((_, j) => (
 <SkeletonBlock
 key={j}
 className="h-2.5 rounded-sm"
 style={{
 width:
 j === 0
 ? '80px'
 : j === columns - 1
 ? '50px'
 : `${40 + ((i * 13 + j * 19) % 55)}px`,
 }}
 />
 ))}
 </div>
 ))}
 </div>
 );
}

/** Card / KPI StatCard skeleton loader with label, value, and delta placeholder. */
export function SkeletonCard({ className }: { className?: string }) {
 return (
 <div className={cn('p-3.5 bg-surface border border-default rounded-md space-y-2', className)}>
 <SkeletonBlock className="h-2.5 w-24 rounded-sm" />
 <SkeletonBlock className="h-6 w-32 rounded-sm" />
 <SkeletonBlock className="h-2 w-16 rounded-sm" />
 </div>
 );
}

/** Grid skeleton for KPI rows and cards. */
export function SkeletonGrid({ count = 4, className }: { count?: number; className?: string }) {
 return (
 <div className={cn('grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3', className)}>
 {Array.from({ length: count }).map((_, i) => (
 <SkeletonCard key={i} />
 ))}
 </div>
 );
}

/** Rich detail page skeleton loader with header, KPI row, process timeline, and main layout. */
export function SkeletonDetail() {
 return (
 <div className="px-5 py-4 space-y-4">
 {/* Header skeleton */}
 <div className="flex items-center justify-between border-b border-default pb-4">
 <div className="space-y-1.5">
 <div className="flex items-center gap-2">
 <SkeletonBlock className="h-6 w-44 rounded-sm" />
 <SkeletonBlock className="h-5 w-20 rounded-full" />
 </div>
 <SkeletonBlock className="h-3 w-32 rounded-sm" />
 </div>
 <div className="flex gap-2">
 <SkeletonBlock className="h-8 w-20 rounded-md" />
 <SkeletonBlock className="h-8 w-24 rounded-md" />
 </div>
 </div>

 {/* Process chain timeline skeleton */}
 <div className="p-3 bg-surface border border-default rounded-md flex items-center justify-between gap-4 overflow-hidden">
 {[1, 2, 3, 4, 5].map((step) => (
 <div key={step} className="flex items-center gap-2 flex-1">
 <SkeletonBlock className="h-3 w-3 rounded-full shrink-0" />
 <SkeletonBlock className="h-3 w-20 rounded-sm" />
 {step < 5 && <SkeletonBlock className="h-0.5 flex-1 rounded-full" />}
 </div>
 ))}
 </div>

 {/* KPI Cards Row */}
 <SkeletonGrid count={4} />

 {/* Main content split */}
 <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
 <div className="lg:col-span-2 space-y-4">
 <SkeletonTable columns={5} rows={6} />
 </div>
 <div className="space-y-3">
 <div className="p-4 bg-surface border border-default rounded-md space-y-3">
 <SkeletonBlock className="h-3.5 w-28 rounded-sm" />
 <SkeletonBlock className="h-3 w-full rounded-sm" />
 <SkeletonBlock className="h-3 w-4/5 rounded-sm" />
 <SkeletonBlock className="h-3 w-2/3 rounded-sm" />
 </div>
 </div>
 </div>
 </div>
 );
}

/** Form skeleton loader with section blocks and input grid placeholders. */
export function SkeletonForm() {
 return (
 <div className="max-w-3xl mx-auto px-5 py-4 space-y-6">
 {[1, 2].map((section) => (
 <div key={section} className="p-4 bg-canvas border border-default rounded-md space-y-4">
 <SkeletonBlock className="h-4 w-36 rounded-sm" />
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
 {[1, 2, 3, 4].map((i) => (
 <div key={i} className="space-y-1.5">
 <SkeletonBlock className="h-3 w-24 rounded-sm" />
 <SkeletonBlock className="h-8 w-full rounded-md" />
 </div>
 ))}
 </div>
 </div>
 ))}
 <div className="flex justify-end gap-2 pt-2">
 <SkeletonBlock className="h-8 w-20 rounded-md" />
 <SkeletonBlock className="h-8 w-28 rounded-md" />
 </div>
 </div>
 );
}

/** Panel-sized skeleton for tab panels (no outer page padding). */
export function SkeletonPanel() {
 return (
 <div className="space-y-3">
 <SkeletonBlock className="h-4 w-40 rounded-sm" />
 <SkeletonTable columns={6} rows={5} />
 </div>
 );
}

/** Full application page skeleton used during initial page/auth loading. */
export function SkeletonPage() {
 return (
 <div className="min-h-screen w-full bg-canvas flex flex-col">
 {/* Topbar skeleton */}
 <div className="h-12 border-b border-default bg-canvas flex items-center px-4 justify-between">
 <div className="flex items-center gap-3">
 <SkeletonBlock className="h-6 w-6 rounded-md" />
 <SkeletonBlock className="h-4 w-28 rounded-sm" />
 </div>
 <div className="flex items-center gap-2">
 <SkeletonBlock className="h-7 w-44 rounded-md" />
 <SkeletonBlock className="h-7 w-7 rounded-full" />
 </div>
 </div>
 <div className="flex-1 flex">
 {/* Sidebar skeleton */}
 <div className="hidden md:block w-56 border-r border-default p-3 space-y-4 bg-surface/30">
 <SkeletonBlock className="h-3 w-20 mb-2 rounded-sm" />
 <div className="space-y-2">
 {[1, 2, 3, 4, 5, 6].map((i) => (
 <SkeletonBlock key={i} className="h-7 w-full rounded-md" />
 ))}
 </div>
 </div>
 {/* Main content skeleton */}
 <div className="flex-1 p-5 space-y-4">
 <div className="flex justify-between items-center mb-2">
 <SkeletonBlock className="h-6 w-48 rounded-sm" />
 <SkeletonBlock className="h-8 w-28 rounded-md" />
 </div>
 <SkeletonGrid count={4} />
 <SkeletonTable columns={6} rows={8} />
 </div>
 </div>
 </div>
 );
}

/** Landing page skeleton screen for ogamiph.dev initial visits (light gray modern theme). */
export function SkeletonLandingPage() {
 return (
 <div className="min-h-screen w-full bg-canvas text-primary flex flex-col font-sans">
 {/* Landing Nav Skeleton */}
 <div className="h-16 border-b border-default bg-canvas flex items-center px-6 justify-between sticky top-0 z-50">
 <div className="flex items-center gap-3">
 <SkeletonBlock className="h-7 w-32 rounded-sm" />
 </div>
 <div className="hidden md:flex items-center gap-6">
 <SkeletonBlock className="h-3 w-16 rounded-sm" />
 <SkeletonBlock className="h-3 w-20 rounded-sm" />
 <SkeletonBlock className="h-3 w-24 rounded-sm" />
 <SkeletonBlock className="h-3 w-16 rounded-sm" />
 </div>
 <div className="flex items-center gap-3">
 <SkeletonBlock className="h-8 w-20 rounded-md" />
 <SkeletonBlock className="h-8 w-28 rounded-md" />
 </div>
 </div>

 {/* Hero Section Skeleton */}
 <div className="max-w-6xl mx-auto w-full px-6 pt-16 pb-12 flex flex-col items-center text-center space-y-6">
 <SkeletonBlock className="h-4 w-44 rounded-full" />
 <SkeletonBlock className="h-12 w-4/5 max-w-3xl rounded-md" />
 <SkeletonBlock className="h-12 w-3/5 max-w-2xl rounded-md" />
 <SkeletonBlock className="h-5 w-2/3 max-w-xl rounded-sm" />
 <div className="flex gap-4 pt-4">
 <SkeletonBlock className="h-11 w-36 rounded-md" />
 <SkeletonBlock className="h-11 w-40 rounded-md" />
 </div>
 </div>

 {/* 3D Showcase / Graphic Container Skeleton */}
 <div className="max-w-5xl mx-auto w-full px-6 py-6">
 <div className="h-80 md:h-96 rounded-md border border-default bg-surface p-6 flex flex-col justify-between overflow-hidden relative ">
 <div className="flex justify-between items-center">
 <SkeletonBlock className="h-4 w-32 rounded-sm" />
 <SkeletonBlock className="h-6 w-20 rounded-full" />
 </div>
 <div className="flex justify-center items-center my-auto">
 <SkeletonBlock className="h-36 w-36 rounded-full" />
 </div>
 <div className="grid grid-cols-3 gap-4 border-t border-subtle pt-4">
 <SkeletonBlock className="h-4 w-full rounded-sm" />
 <SkeletonBlock className="h-4 w-full rounded-sm" />
 <SkeletonBlock className="h-4 w-full rounded-sm" />
 </div>
 </div>
 </div>

 {/* Stats Bar Skeleton */}
 <div className="max-w-6xl mx-auto w-full px-6 py-12 border-t border-subtle">
 <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
 {[1, 2, 3, 4].map((i) => (
 <div key={i} className="p-4 rounded-md bg-surface border border-default space-y-2">
 <SkeletonBlock className="h-8 w-24 rounded-sm" />
 <SkeletonBlock className="h-3 w-28 rounded-sm" />
 </div>
 ))}
 </div>
 </div>
 </div>
 );
}

/** Login / Auth page skeleton loader. */
export function SkeletonLoginPage() {
 return (
 <div className="grid min-h-screen w-full bg-canvas text-primary lg:grid-cols-2 font-sans">
 {/* Left Brand Panel Skeleton (lg+) */}
 <div className="hidden border-r border-default bg-surface p-12 lg:flex lg:flex-col lg:justify-between">
 <div className="flex items-center gap-3">
 <SkeletonBlock className="h-8 w-8 rounded-md" />
 <SkeletonBlock className="h-4 w-32 rounded-sm" />
 </div>
 <div className="mx-auto w-full max-w-sm aspect-square border border-default rounded-md p-6 flex flex-col justify-between bg-canvas">
 <div className="flex justify-between">
 <SkeletonBlock className="h-3 w-16 rounded-sm" />
 <SkeletonBlock className="h-3 w-16 rounded-sm" />
 </div>
 <div className="flex justify-center items-center my-auto">
 <SkeletonBlock className="h-32 w-32 rounded-full" />
 </div>
 <SkeletonBlock className="h-3 w-full rounded-sm" />
 </div>
 <div className="space-y-2">
 <SkeletonBlock className="h-6 w-48 rounded-sm" />
 <SkeletonBlock className="h-3 w-36 rounded-sm" />
 </div>
 </div>

 {/* Right Form Skeleton */}
 <div className="flex flex-col items-center justify-center p-6 sm:p-12">
 <div className="w-full max-w-sm space-y-6">
 <div className="space-y-2">
 <SkeletonBlock className="h-7 w-32 rounded-sm" />
 <SkeletonBlock className="h-4 w-56 rounded-sm" />
 </div>
 <div className="space-y-4 pt-2">
 <div className="space-y-1.5">
 <SkeletonBlock className="h-3 w-20 rounded-sm" />
 <SkeletonBlock className="h-9 w-full rounded-md" />
 </div>
 <div className="space-y-1.5">
 <SkeletonBlock className="h-3 w-24 rounded-sm" />
 <SkeletonBlock className="h-9 w-full rounded-md" />
 </div>
 <div className="flex items-center justify-between pt-1">
 <SkeletonBlock className="h-4 w-28 rounded-sm" />
 <SkeletonBlock className="h-3 w-24 rounded-sm" />
 </div>
 <SkeletonBlock className="h-9 w-full rounded-md mt-2" />
 </div>
 <div className="pt-4 flex justify-center">
 <SkeletonBlock className="h-3 w-40 rounded-sm" />
 </div>
 </div>
 </div>
 </div>
 );
}

/** Executive / KPI Dashboard page skeleton screen. */
export function SkeletonDashboard() {
 return (
 <div className="p-5 space-y-5 font-sans">
 {/* Top Header + Action Row */}
 <div className="flex justify-between items-center">
 <div className="space-y-1">
 <SkeletonBlock className="h-6 w-52 rounded-sm" />
 <SkeletonBlock className="h-3 w-40 rounded-sm" />
 </div>
 <div className="flex gap-2">
 <SkeletonBlock className="h-8 w-24 rounded-md" />
 <SkeletonBlock className="h-8 w-32 rounded-md" />
 </div>
 </div>

 {/* KPI Cards Row */}
 <SkeletonGrid count={4} />

 {/* Widget Grid */}
 <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
 <div className="lg:col-span-2 space-y-4">
 <div className="p-4 border border-default rounded-md bg-surface space-y-3">
 <SkeletonBlock className="h-4 w-36 rounded-sm" />
 <SkeletonBlock className="h-48 w-full rounded-md" />
 </div>
 <SkeletonTable columns={5} rows={5} />
 </div>
 <div className="space-y-4">
 <div className="p-4 border border-default rounded-md bg-surface space-y-3">
 <SkeletonBlock className="h-4 w-32 rounded-sm" />
 <div className="space-y-2">
 {[1, 2, 3, 4].map((i) => (
 <SkeletonBlock key={i} className="h-10 w-full rounded-md" />
 ))}
 </div>
 </div>
 </div>
 </div>
 </div>
 );
}

/** Kanban / Board page skeleton screen. */
export function SkeletonKanban() {
 return (
 <div className="p-5 space-y-4 font-sans">
 <div className="flex justify-between items-center">
 <SkeletonBlock className="h-6 w-48 rounded-sm" />
 <SkeletonBlock className="h-8 w-28 rounded-md" />
 </div>
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-2">
 {[1, 2, 3, 4].map((col) => (
 <div key={col} className="p-3 bg-surface border border-default rounded-md space-y-3 min-h-[400px]">
 <div className="flex justify-between items-center pb-2 border-b border-subtle">
 <SkeletonBlock className="h-4 w-28 rounded-sm" />
 <SkeletonBlock className="h-4 w-6 rounded-full" />
 </div>
 {[1, 2, 3].map((card) => (
 <div key={card} className="p-3 bg-canvas border border-default rounded-md space-y-2">
 <SkeletonBlock className="h-3.5 w-3/4 rounded-sm" />
 <SkeletonBlock className="h-2.5 w-1/2 rounded-sm" />
 <div className="flex justify-between pt-1">
 <SkeletonBlock className="h-3 w-16 rounded-sm" />
 <SkeletonBlock className="h-5 w-5 rounded-full" />
 </div>
 </div>
 ))}
 </div>
 ))}
 </div>
 </div>
 );
}
