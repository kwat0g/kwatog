import { Routes, Route, Navigate } from 'react-router-dom';

/**
 * Placeholder router — fully populated in Task 9 with auth layout,
 * AuthGuard / ModuleGuard / PermissionGuard wrappers, and lazy-loaded pages.
 */
export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route
        path="*"
        element={
          <div className="min-h-screen flex items-center justify-center text-sm text-zinc-500">
            Ogami ERP — bootstrap placeholder
          </div>
        }
      />
    </Routes>
  );
}
