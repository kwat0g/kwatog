import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTablePrefsStore } from '../tablePrefsStore';

vi.mock('@/api/auth', () => ({
  authApi: { updatePreferences: vi.fn().mockResolvedValue({}) },
}));

import { authApi } from '@/api/auth';

describe('tablePrefsStore', () => {
  beforeEach(() => {
    useTablePrefsStore.getState().clear();
    vi.clearAllMocks();
  });

  it('hydrates preferences for one user without writing them back', () => {
    useTablePrefsStore.getState().hydrate('user-1', {
      'inventory.items': { density: 'compact', hiddenColumns: ['supplier'] },
    });

    expect(useTablePrefsStore.getState().byTable['inventory.items']).toEqual({
      density: 'compact', hiddenColumns: ['supplier'],
    });
    expect(authApi.updatePreferences).not.toHaveBeenCalled();
  });

  it('persists density per user and resets state when account changes', () => {
    useTablePrefsStore.getState().hydrate('user-1');
    useTablePrefsStore.getState().setDensity('inventory.items', 'compact');
    expect(authApi.updatePreferences).toHaveBeenCalledWith({
      table_preferences: { 'inventory.items': { density: 'compact' } },
    });

    useTablePrefsStore.getState().hydrate('user-2', {
      'inventory.items': { density: 'spacious' },
    });
    expect(useTablePrefsStore.getState().byTable['inventory.items']?.density).toBe('spacious');
  });

  it('persists visibility changes and deletes reset table preferences', () => {
    useTablePrefsStore.getState().hydrate('user-1');
    useTablePrefsStore.getState().setHiddenColumns('quality.calibration', ['inspector']);
    useTablePrefsStore.getState().reset('quality.calibration');

    expect(authApi.updatePreferences).toHaveBeenLastCalledWith({
      table_preferences: { 'quality.calibration': null },
    });
    expect(useTablePrefsStore.getState().byTable['quality.calibration']).toBeUndefined();
  });
});
