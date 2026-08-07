/**
 * Process capability (Cp / Cpk) types.
 *
 * Control-chart types (SpcControlChart, SpcDataPoint, SpcAlert and their enums)
 * were removed in the 2026-08-07 scope cut along with the charting backend.
 */

export interface SpcCapabilityResult {
 cp: number;
 cpk: number;
 cpu: number;
 cpl: number;
 mean: number;
 std_dev: number;
 sample_count: number;
 usl: number;
 lsl: number;
 histogram: {
 bins: number[];
 bin_edges: number[];
 lsl: number;
 usl: number;
 };
}

export interface RunCapabilityData {
 product_id: string;
 spec_item_id: string;
}
