import { Outlet } from 'react-router-dom';

export function AuthLayout() {
  return (
    <div className="min-h-screen w-full flex items-center justify-center bg-canvas px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="flex items-center justify-center gap-2 mb-6">
          <span className="h-8 w-8 rounded-md bg-primary text-canvas inline-flex items-center justify-center font-medium text-base">
            O
          </span>
          <span className="text-md font-medium">Ogami ERP</span>
        </div>
        <Outlet />
      </div>
    </div>
  );
}

export default AuthLayout;
