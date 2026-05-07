// U3 — Re-export leave.tsx under the new "/self-service/leaves" route
// to align with the bottom-nav slug. Existing /self-service/leave route
// remains for backward compatibility (registered separately in App.tsx).
export { default } from './leave';
