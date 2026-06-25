import { client } from '../client';
import type { TrainingMatrixData } from '@/types/hr';

export interface TrainingMatrixParams {
  department_id?: string;
}

export const trainingMatrixApi = {
  index: (params?: TrainingMatrixParams) =>
    client.get<{ data: TrainingMatrixData }>('/hr/training/matrix', { params }).then((r) => r.data.data),
};
