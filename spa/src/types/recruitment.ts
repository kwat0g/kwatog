export type JobPostingStatus = 'draft' | 'open' | 'closed' | 'filled';
export type ApplicationStage = 'new' | 'screening' | 'interview' | 'offer' | 'hired' | 'rejected';
export type InterviewOutcome = 'pending' | 'passed' | 'failed';
export type EmploymentType = 'regular' | 'probationary' | 'contractual' | 'project_based';

export interface JobPosting {
  id: string;
  posting_number: string;
  title: string;
  description: string;
  requirements: string;
  employment_type: EmploymentType;
  salary_range_min: string | null;
  salary_range_max: string | null;
  show_salary: boolean;
  status: JobPostingStatus;
  slots: number;
  posted_at: string | null;
  closes_at: string | null;
  position?: { id: string; title: string } | null;
  department?: { id: string; name: string } | null;
  created_by?: { id: string; name: string } | null;
  application_count?: number;
  created_at: string;
  updated_at: string;
}

export interface PublicJobPosting {
  id: string;
  posting_number: string;
  title: string;
  description: string;
  requirements: string;
  employment_type: EmploymentType;
  salary_range: { min: string; max: string } | null;
  department: { id: string; name: string };
  posted_at: string;
  closes_at: string | null;
}

export interface JobApplication {
  id: string;
  application_number: string;
  tracking_code: string;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  phone: string;
  cover_letter: string | null;
  stage: ApplicationStage;
  stage_label: string;
  rejected_at_stage: string | null;
  rejection_reason: string | null;
  applied_at: string;
  job_posting?: JobPosting;
  interviews?: ApplicationInterview[];
  notes?: ApplicationNote[];
  converted_employee?: { id: string; employee_no: string } | null;
  created_at: string;
  updated_at: string;
}

export interface ApplicationInterview {
  id: string;
  scheduled_at: string;
  location: string | null;
  interviewer_name: string;
  notes: string | null;
  outcome: InterviewOutcome | null;
  created_by?: { id: string; name: string };
  created_at: string;
}

export interface ApplicationNote {
  id: number;
  body: string;
  user: { id: string; name: string };
  created_at: string;
}

export interface TrackingInfo {
  tracking_code: string;
  position: string;
  applied_at: string;
  status: string;
  interview: { scheduled_at: string; location: string } | null;
}

export interface CreateJobPostingData {
  title: string;
  department_id: string;
  position_id?: string | null;
  description: string;
  requirements: string;
  employment_type: EmploymentType;
  salary_range_min?: string | null;
  salary_range_max?: string | null;
  show_salary?: boolean;
  slots?: number;
  closes_at?: string | null;
}
