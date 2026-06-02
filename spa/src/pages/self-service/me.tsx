// Task SS2 — the "Me" hub now lives in profile.tsx (profile info + account
// settings). The legacy /self-service/me route re-exports it so existing
// links keep working; the bottom-nav "Me" tab points at /self-service/profile.
export { default } from './profile';
