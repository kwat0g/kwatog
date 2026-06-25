export type CycleType = 'annual' | 'semi_annual' | 'quarterly' | 'probationary';
export type CycleStatus = 'draft' | 'active' | 'closed';
export type ReviewStatus = 'pending' | 'in_progress' | 'submitted' | 'acknowledged';

export interface ReviewCycle {
  id: string;
  name: string;
  cycle_type: CycleType;
  status: CycleStatus;
  start_date: string;
  end_date: string;
  created_at: string;
}

export interface PerformanceReview {
  id: string;
  cycle: { id: string; name: string };
  employee: { id: string; first_name: string; last_name: string };
  reviewer: { id: string; first_name: string; last_name: string };
  status: ReviewStatus;
  overall_score: string | null;
  overall_rating: string | null;
  submitted_at: string | null;
  acknowledged_at: string | null;
}

export interface CreateCycleData {
  name: string;
  cycle_type: CycleType;
  start_date: string;
  end_date: string;
}

export interface CreateReviewData {
  cycle_id: string;
  employee_id: string;
  reviewer_id: string;
}

export interface SubmitReviewData {
  ratings: Record<string, number>;
  strengths: string;
  improvements: string;
  goals: string;
  overall_score: string;
  overall_rating: string;
}
