// U3 — Re-export me.tsx under the new "/self-service/profile" route
// to align with the bottom-nav slug. Existing /self-service/me route
// remains for backward compatibility (registered separately in App.tsx).
export { default } from './me';
