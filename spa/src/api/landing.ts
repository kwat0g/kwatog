import { unwrappingClient as client, getCsrfCookie } from './client';

export interface ContactInquiryPayload {
 full_name: string;
 company?: string;
 email: string;
 phone?: string;
 message: string;
}
export interface ContactInquiryResponse { message: string }
export interface LandingContact {
 legal_name: string | null;
 address: string | null;
 phone: string | null;
 sales_email: string | null;
 company_email: string | null;
 public_url: string | null;
 latitude: number | null;
 longitude: number | null;
}
export interface LandingStat { id: string; value: number; prefix?: string; suffix?: string; decimals?: number; label: string }
export interface LandingProofPoint { value: string; label: string }
export interface LandingCapability { id: string; title: string; icon: string; blurb: string; points: string[]; tag: string }
export interface LandingProcessStep { index: string; title: string; icon: string; body: string }
export interface LandingQualityPillar { id: string; title: string; icon: string; body: string }
export interface LandingQualityPolicy {
 standard: string;
 certification_title: string;
 certification_body: string;
 conformance_title: string;
 conformance_body: string;
}
export interface LandingPartSpec { id: string; name: string; material: string; tolerance: string; application: string; feature: string }
export interface LandingPhilippinesCopy { eyebrow: string; title: string; body: string }
export interface LandingHeroCopy { line_one: string; line_two: string; line_three: string }
export interface LandingSectionCopy {
 trust_heading: string;
 hero_description: string;
 quality_title: string;
 contact_title: string;
 contact_intro: string;
 contact_success_title: string;
 contact_success_body: string;
 capabilities_title: string;
 process_title: string;
 part_showcase_title: string;
 capabilities_eyebrow: string;
 part_showcase_eyebrow: string;
 capabilities_intro: string;
 process_eyebrow: string;
 process_intro: string;
 quality_intro: string;
 part_showcase_intro: string;
 footer_description: string;
 newsletter_description: string;
 page_title_suffix: string;
 nav_links: Array<{ label: string; href: string }>;
 footer_company_links: Array<{ label: string; href: string }>;
 hero_cta: {
 quote_label: string;
 quote_href: string;
 explore_label: string;
 explore_href: string;
 careers_label: string;
 careers_href: string;
 };
}
export interface LandingContent {
 oem_partners: string[];
 quality_methods: string[];
 trust_points: string[];
 philippines_points: LandingProofPoint[];
 stats: LandingStat[];
 capabilities: LandingCapability[];
 process_steps: LandingProcessStep[];
 quality_pillars: LandingQualityPillar[];
 quality_policy: LandingQualityPolicy;
 part_specs: LandingPartSpec[];
 philippines_copy: LandingPhilippinesCopy;
 hero_copy: LandingHeroCopy;
 section_copy: LandingSectionCopy;
}

export const landingApi = {
 contact: () => client.get<LandingContact>('/landing/contact').then((r) => r.data),
 content: () => client.get<LandingContent>('/landing/content').then((r) => r.data),
 // Plain JSON now that the drawing upload is gone — no FormData, so no
 // multipart boundary to get wrong.
 submitInquiry: async (payload: ContactInquiryPayload): Promise<ContactInquiryResponse> => {
 await getCsrfCookie();
 const { data } = await client.post<ContactInquiryResponse>('/landing/contact-inquiry', payload);
 return data;
 },
 subscribeNewsletter: async (email: string): Promise<{ message: string }> => {
 await getCsrfCookie();
 const { data } = await client.post<{ message: string }>('/landing/newsletter', { email });
 return data;
 },
 downloadQualityPolicy: async (): Promise<Blob> => {
 const { data } = await client.get<Blob>('/landing/quality-policy', { responseType: 'blob' });
 return data;
 },
};
