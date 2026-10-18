export type ImportStatusValue = 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed';

export const IMPORT_STATUS_LABELS: Record<ImportStatusValue, string> = {
    pending: 'Pending',
    processing: 'Processing',
    completed: 'Completed',
    completed_with_errors: 'Completed with errors',
    failed: 'Failed',
};

export type ImportRowError = {
    row_number: number;
    errors: string[];
};

export type ImportSummary = {
    id: number;
    status: ImportStatusValue;
    original_filename: string;
    total_rows: number;
    processed_rows: number;
    success_rows: number;
    failed_rows: number;
    created_at: string;
    errors?: ImportRowError[];
};
