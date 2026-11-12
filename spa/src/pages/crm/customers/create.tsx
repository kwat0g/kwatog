import { useEffect, useRef, useState, type ReactNode } from 'react';
import { FormProvider, useForm, useWatch, type FieldPath } from 'react-hook-form';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { portalAccessApi } from '@/api/b2b/portal-access';
import { crmCustomersApi } from '@/api/crm/customers';
import { businessPoliciesApi } from '@/api/businessPolicies';
import type { Customer } from '@/types/accounting';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { reconcileRealtimeMutationResponse } from '@/lib/realtime/invalidationMap';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { CustomerForm, customerCreateResolver, customerSchema, type CustomerFormValues } from './form';
import { CustomerAgreementSetupStep } from './CustomerAgreementSetupStep';
import { CustomerAgreementPdfIntake } from './CustomerAgreementPdfIntake';
import type { ProductOrderLimitSuggestion, ProductWarrantySuggestion } from './customerAgreementPdfExtraction';
import { CustomerProductOrderingSetup, type CustomerProductOrderingDraft } from './CustomerProductOrderingSetup';
import { FormActions } from '@/components/ui/FormActions';
import { customerLegalNameField, normalizeCustomerText } from '@/lib/validation/customerFields';
type FormValues = CustomerFormValues;
type CustomerWizardStep = {
 title: string;
 shortTitle: string;
 description: string;
 fields: FieldPath<FormValues>[];
};

const CUSTOMER_CREATE_STEPS: CustomerWizardStep[] = [
 {
  title: 'Customer & contact',
  shortTitle: 'Contact',
  description: 'Set up the customer account and the people your team should contact.',
  fields: ['name', 'contact_person', 'contact_person_role', 'email', 'phone', 'is_active'],
 },
 {
  title: 'Billing & payment',
  shortTitle: 'Billing',
  description: 'Add the invoice address and default payment expectations.',
  fields: ['address', 'payment_terms_days', 'credit_limit', 'allowed_payment_methods'],
 },
 {
  title: 'Delivery',
  shortTitle: 'Delivery',
  description: 'Enter the receiving details and pin the exact delivery location.',
  fields: ['delivery_address', 'delivery_contact_person', 'delivery_contact_phone', 'delivery_latitude', 'delivery_longitude'],
 },
 {
  title: 'Agreement & products',
  shortTitle: 'Agreement',
  description: 'Review agreement terms, product access, and warranty choices by product.',
  fields: ['agreement_starts_on', 'agreement_ends_on', 'shipping_payer'],
 },
 {
  title: 'Review',
  shortTitle: 'Review',
  description: 'Check the customer setup before creating the record.',
  fields: [],
 },
];

const PAYMENT_METHOD_LABELS: Record<string, string> = {
 cash: 'Cash',
 check: 'Check',
 bank_transfer: 'Bank transfer',
};

function CustomerCreateStepper({
 currentStep,
 furthestStep,
 onSelect,
}: {
 currentStep: number;
 furthestStep: number;
 onSelect: (step: number) => void;
}) {
 return (
  <nav aria-label="Customer setup progress" className="mb-6">
   <ol className="grid grid-cols-5 gap-1 sm:gap-2">
    {CUSTOMER_CREATE_STEPS.map((step, index) => {
     const isCurrent = currentStep === index;
     const isComplete = index < furthestStep;
     const canSelect = index <= furthestStep;
     return (
      <li key={step.title} className="min-w-0">
       <button
        type="button"
        aria-label={`Step ${index + 1}: ${step.title}`}
        aria-current={isCurrent ? 'step' : undefined}
        disabled={!canSelect}
        onClick={() => onSelect(index)}
        className={`flex min-h-11 w-full min-w-0 flex-col items-center justify-center gap-1 rounded-md px-1 py-1 text-center focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-55 ${isCurrent ? 'text-primary' : 'text-muted hover:bg-subtle'}`}
       >
        <span aria-hidden="true" className={`flex h-7 w-7 items-center justify-center rounded-full border text-xs font-semibold ${isCurrent ? 'border-accent bg-accent text-accent-fg' : isComplete ? 'border-accent/40 bg-accent/10 text-accent' : 'border-default bg-surface text-muted'}`}>
         {isComplete ? '✓' : index + 1}
        </span>
        <span className="hidden text-caption leading-tight sm:block">{step.shortTitle}</span>
       </button>
      </li>
     );
    })}
   </ol>
   <span className="sr-only" aria-live="polite">Step {currentStep + 1} of {CUSTOMER_CREATE_STEPS.length}: {CUSTOMER_CREATE_STEPS[currentStep].title}</span>
  </nav>
 );
}

function ReviewCard({
 title,
 onEdit,
 children,
}: {
 title: string;
 onEdit: () => void;
 children: ReactNode;
}) {
 return (
  <section className="min-w-0 rounded-md border border-default bg-canvas p-4 sm:p-5" aria-label={title}>
   <div className="mb-3 flex items-center justify-between gap-3 border-b border-subtle pb-3">
    <h3 className="text-label font-semibold text-primary">{title}</h3>
    <Button type="button" size="touch" variant="ghost" onClick={onEdit} aria-label={`Edit ${title}`}>Edit</Button>
   </div>
   {children}
  </section>
 );
}

function ReviewField({ label, value }: { label: string; value: string }) {
 return (
  <div className="min-w-0">
   <dt className="text-caption text-muted">{label}</dt>
   <dd className="mt-0.5 break-words text-sm text-primary">{value || 'Not provided'}</dd>
  </div>
 );
}

function CustomerCreateReview({
 values,
 orderingSetup,
 agreementFiles,
 productReferences,
 warrantySuggestions,
 orderLimitSuggestions,
 inviteToPortal,
 onInviteToPortalChange,
 authorizedSignatory,
 onAuthorizedSignatoryChange,
 onEdit,
}: {
 values: FormValues;
 orderingSetup: CustomerProductOrderingDraft;
 agreementFiles: File[];
 productReferences: string[];
 warrantySuggestions: ProductWarrantySuggestion[];
 orderLimitSuggestions: ProductOrderLimitSuggestion[];
 inviteToPortal: boolean;
 onInviteToPortalChange: (checked: boolean) => void;
 authorizedSignatory: boolean;
 onAuthorizedSignatoryChange: (checked: boolean) => void;
 onEdit: (step: number) => void;
}) {
 const selectedPaymentMethods = values.allowed_payment_methods?.length
  ? values.allowed_payment_methods.map((method) => PAYMENT_METHOD_LABELS[method] ?? method).join(', ')
  : 'All methods allowed';
 const deliveryPin = values.delivery_latitude != null && values.delivery_longitude != null
  ? `${values.delivery_latitude}, ${values.delivery_longitude}`
  : 'Not selected';
 const catalogAccess = orderingSetup.portal_catalog_mode === 'standard'
  ? `Standard catalog plus ${orderingSetup.portal_product_ids.length} selected`
  : `${orderingSetup.portal_product_ids.length} customer-specific products`;
 const portalInviteDisabled = !values.is_active || !values.contact_person?.trim() || !values.email?.trim();

 return (
  <div className="space-y-4">
   <p className="text-sm text-secondary">Review each section. You can return to edit any details before the customer is created.</p>
   <div className="grid gap-3 lg:grid-cols-2">
    <ReviewCard title="Customer & contact" onEdit={() => onEdit(0)}>
     <dl className="grid gap-3 sm:grid-cols-2">
      <ReviewField label="Customer name" value={values.name} />
      <ReviewField label="Account status" value={values.is_active ? 'Active' : 'Inactive'} />
      <ReviewField label="Contact person" value={values.contact_person || ''} />
      <ReviewField label="Contact role" value={values.contact_person_role || ''} />
      <ReviewField label="Email" value={values.email || ''} />
      <ReviewField label="Phone" value={values.phone || ''} />
     </dl>
    </ReviewCard>

    <ReviewCard title="Billing & payment" onEdit={() => onEdit(1)}>
     <dl className="grid gap-3 sm:grid-cols-2">
      <ReviewField label="Billing address" value={values.address || ''} />
      <ReviewField label="Payment terms" value={`${values.payment_terms_days ?? 'Not set'} days`} />
      <ReviewField label="Credit limit" value={typeof values.credit_limit === 'number' ? `₱${values.credit_limit}` : 'Not set'} />
      <ReviewField label="Allowed methods" value={selectedPaymentMethods} />
     </dl>
    </ReviewCard>

    <ReviewCard title="Delivery" onEdit={() => onEdit(2)}>
     <dl className="grid gap-3 sm:grid-cols-2">
      <ReviewField label="Delivery address" value={values.delivery_address || ''} />
      <ReviewField label="Map pin" value={deliveryPin} />
      <ReviewField label="Receiving contact" value={values.delivery_contact_person || ''} />
      <ReviewField label="Contact phone" value={values.delivery_contact_phone || ''} />
     </dl>
    </ReviewCard>

    <ReviewCard title="Agreement & products" onEdit={() => onEdit(3)}>
     <dl className="grid gap-3 sm:grid-cols-2">
      <ReviewField label="Agreement period" value={[values.agreement_starts_on, values.agreement_ends_on].filter(Boolean).join(' – ') || 'Not specified'} />
      <ReviewField label="Shipping paid by" value={values.shipping_payer ? values.shipping_payer === 'company' ? 'Company' : 'Customer' : 'Not specified'} />
      <ReviewField label="Product access" value={catalogAccess} />
      <ReviewField label="Warranty choices" value={`${orderingSetup.product_warranties.length} per-product choices`} />
      <ReviewField label="PDF product references" value={`${productReferences.length} found · ${warrantySuggestions.length} warranty terms · ${orderLimitSuggestions.length} quantity limits`} />
      <ReviewField label="Agreement PDFs" value={agreementFiles.length ? agreementFiles.map((file) => file.name).join(', ') : 'None selected'} />
     </dl>
     <p className="mt-3 rounded-md bg-subtle px-3 py-2 text-xs leading-relaxed text-secondary">The customer record is created first. You can attach the selected original PDFs in the agreement setup that follows.</p>
    </ReviewCard>
   </div>
   <section aria-label="Portal invitation" className="rounded-md border border-default bg-canvas p-4 sm:p-5">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
     <div>
      <h3 className="text-label font-semibold text-primary">Customer portal access</h3>
      <p className="mt-1 max-w-2xl text-sm text-secondary">Portal access is optional. The invitation is queued only after the customer record is created.</p>
     </div>
     <Checkbox
      checked={inviteToPortal && !portalInviteDisabled}
      disabled={portalInviteDisabled}
      onChange={(event) => onInviteToPortalChange(event.target.checked)}
      label={<span><span className="font-medium text-primary">Send portal invitation after creation</span><span className="block text-xs text-muted">Uses the contact name and email above.</span></span>}
     />
    </div>
    {portalInviteDisabled && (
     <p className="mt-2 text-xs text-muted">{!values.is_active
      ? 'Activate the customer to enable portal invitations.'
      : 'Add a contact name and email on Step 1 to enable portal invitations.'}</p>
    )}
    {inviteToPortal && !portalInviteDisabled && (
     <Checkbox
      className="mt-3"
      checked={authorizedSignatory}
      onChange={(event) => onAuthorizedSignatoryChange(event.target.checked)}
      label={<span><span className="font-medium text-primary">Authorized signatory</span><span className="block text-xs text-muted">Allows this contact to sign customer agreements.</span></span>}
     />
    )}
   </section>
  </div>
 );
}
type CustomerValuesWithoutFallbackWarranty = Omit<FormValues,
 'warranty_duration_days' | 'warranty_report_window_days' | 'warranty_start_basis' | 'warranty_covered_issues' | 'warranty_remedies'>;

function withoutFallbackWarranty(values: FormValues): CustomerValuesWithoutFallbackWarranty {
 const customerValues = { ...values };
 delete customerValues.warranty_duration_days;
 delete customerValues.warranty_report_window_days;
 delete customerValues.warranty_start_basis;
 delete customerValues.warranty_covered_issues;
 delete customerValues.warranty_remedies;
 return customerValues;
}

export default function CrmCustomerCreatePage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [searchParams, setSearchParams] = useSearchParams();
 const setupCustomerId = searchParams.get('setup');
 const [createdCustomer, setCreatedCustomer] = useState<Customer | null>(null);
 const [pendingAgreementFiles, setPendingAgreementFiles] = useState<File[]>([]);
 const [productReferences, setProductReferences] = useState<string[]>([]);
 const [productWarrantySuggestions, setProductWarrantySuggestions] = useState<ProductWarrantySuggestion[]>([]);
 const [productOrderLimitSuggestions, setProductOrderLimitSuggestions] = useState<ProductOrderLimitSuggestion[]>([]);
 const [productOrderingSetup, setProductOrderingSetup] = useState<CustomerProductOrderingDraft>({
  portal_catalog_mode: 'restricted', portal_product_ids: [], product_order_limits: [], product_warranties: [],
 });
 const [productCatalogReady, setProductCatalogReady] = useState(false);
 const [productOrderingValid, setProductOrderingValid] = useState(false);
 const [inviteToPortal, setInviteToPortal] = useState(false);
 const [authorizedSignatory, setAuthorizedSignatory] = useState(false);
 const [isReadingAgreementPdf, setIsReadingAgreementPdf] = useState(false);
 const [currentStep, setCurrentStep] = useState(0);
 const [furthestStep, setFurthestStep] = useState(0);
 const stepHeadingRef = useRef<HTMLHeadingElement>(null);
 const setupCustomerQuery = useQuery({
  queryKey: ['crm', 'customers', 'detail', setupCustomerId],
  queryFn: () => crmCustomersApi.show(setupCustomerId!),
  enabled: Boolean(setupCustomerId) && !createdCustomer,
 });
 const activeCustomer = createdCustomer ?? setupCustomerQuery.data ?? null;

 const methods = useForm<z.input<typeof customerSchema>, unknown, FormValues>({
 resolver: customerCreateResolver,
 defaultValues: {
 name: '',
 contact_person: '',
 contact_person_role: '',
 email: '',
 phone: '',
 address: '',
 delivery_address: '',
 delivery_contact_person: '',
 delivery_contact_phone: '',
 delivery_latitude: '',
 delivery_longitude: '',
 allowed_payment_methods: [], shipping_payer: '', agreement_starts_on: '', agreement_ends_on: '',
 is_active: true,
 },
 });

 const enteredCustomerName = useWatch({ control: methods.control, name: 'name' }) ?? '';
 const normalizedCustomerName = normalizeCustomerText(enteredCustomerName).toLowerCase();
 const customerNameCanBeChecked = normalizedCustomerName.length > 0 && normalizedCustomerName.length <= 200;
 const customerNameInvalid = !customerLegalNameField.safeParse(enteredCustomerName).success;
 const [debouncedCustomerName, setDebouncedCustomerName] = useState('');
 useEffect(() => {
  const timeout = window.setTimeout(() => setDebouncedCustomerName(normalizedCustomerName), 250);
  return () => window.clearTimeout(timeout);
 }, [normalizedCustomerName]);

 const customerNameCheck = useQuery({
  queryKey: ['crm', 'customers', 'name-check', debouncedCustomerName],
  queryFn: () => crmCustomersApi.checkName(debouncedCustomerName),
  enabled: currentStep === 0 && customerNameCanBeChecked && debouncedCustomerName === normalizedCustomerName,
  retry: false,
  staleTime: 0,
 });
 const nameCheckMatchesCurrent = customerNameCheck.data?.normalized_name === normalizedCustomerName;
 const duplicateCustomerName = Boolean(nameCheckMatchesCurrent && customerNameCheck.data?.exists);
 const checkingCustomerName = customerNameCanBeChecked && (
  debouncedCustomerName !== normalizedCustomerName
  || customerNameCheck.isFetching
  || (!nameCheckMatchesCurrent && !customerNameCheck.isError)
 );
 const customerNameCheckFailed = Boolean(
  customerNameCanBeChecked
  && debouncedCustomerName === normalizedCustomerName
  && customerNameCheck.isError
  && !nameCheckMatchesCurrent,
 );

 const { handleSubmit, setError, setValue, formState: { isSubmitting, dirtyFields } } = methods;
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });

 useEffect(() => {
 if (policies && !dirtyFields.payment_terms_days) setValue('payment_terms_days', policies.customer_payment_terms_days);
 }, [policies, setValue, dirtyFields.payment_terms_days]);

 useEffect(() => {
  stepHeadingRef.current?.focus({ preventScroll: true });
 }, [currentStep]);

 const goToStep = async (step: number) => {
  if (step < 0 || step >= CUSTOMER_CREATE_STEPS.length || step > furthestStep) return;
  if (step > currentStep) {
   for (let index = currentStep; index < step; index += 1) {
    if (index === 0 && (checkingCustomerName || duplicateCustomerName || customerNameCheckFailed || customerNameInvalid)) {
     setCurrentStep(0);
     return;
    }
    if (index === 3 && (!productCatalogReady || !productOrderingValid)) {
     setCurrentStep(3);
     return;
    }
    const isValid = await methods.trigger(CUSTOMER_CREATE_STEPS[index].fields, { shouldFocus: true });
    if (!isValid) {
     setCurrentStep(index);
     return;
    }
   }
  }
  setCurrentStep(step);
 };

 const goNext = async () => {
  if (currentStep >= CUSTOMER_CREATE_STEPS.length - 1 || isReadingAgreementPdf || isSubmitting || mutation.isPending) return;
  if (currentStep === 0 && (checkingCustomerName || duplicateCustomerName || customerNameCheckFailed)) return;
  if (currentStep === 3 && (!productCatalogReady || !productOrderingValid)) return;
  const isValid = await methods.trigger(CUSTOMER_CREATE_STEPS[currentStep].fields, { shouldFocus: true });
  if (!isValid) return;
  const nextStep = currentStep + 1;
  setFurthestStep((furthest) => Math.max(furthest, nextStep));
  setCurrentStep(nextStep);
 };

 const stepForField = (field: string): number => {
  if (field.startsWith('portal_product_ids') || field.startsWith('product_order_limits') || field.startsWith('product_warranties')) return 3;
  const rootField = field.split(/[.[\]]/)[0] as FieldPath<FormValues>;
  const matchingStep = CUSTOMER_CREATE_STEPS.findIndex((step) => step.fields.includes(rootField));
  return matchingStep >= 0 && matchingStep < 4 ? matchingStep : 0;
 };

 const handleFinalInvalid = (errors: Record<string, unknown>) => {
  onFormInvalid<FormValues>()(errors as never);
  const firstInvalidField = Object.keys(errors)[0];
  if (firstInvalidField) setCurrentStep(stepForField(firstInvalidField));
 };

 const mutation = useMutation({
 mutationFn: async ({ values, orderingSetup, sendPortalInvitation, isAuthorizedSignatory }: {
  values: FormValues;
  orderingSetup: CustomerProductOrderingDraft;
  sendPortalInvitation: boolean;
  isAuthorizedSignatory: boolean;
 }) => {
 const customerValues = withoutFallbackWarranty(values);
 const payload = {
 ...customerValues,
 contact_person: values.contact_person || undefined,
 contact_person_role: values.contact_person_role || undefined,
 email: values.email || undefined,
 phone: values.phone || undefined,
 address: values.address || undefined,
 delivery_address: values.delivery_address || undefined,
 delivery_contact_person: values.delivery_contact_person || undefined,
 delivery_contact_phone: values.delivery_contact_phone || undefined,
 delivery_latitude: values.delivery_latitude,
 delivery_longitude: values.delivery_longitude,
 allowed_payment_methods: values.allowed_payment_methods?.length ? values.allowed_payment_methods : null,
 shipping_payer: values.shipping_payer || null,
 agreement_starts_on: values.agreement_starts_on || null,
 agreement_ends_on: values.agreement_ends_on || null,
 credit_limit: values.credit_limit != null ? String(values.credit_limit) : null,
 portal_catalog_mode: orderingSetup.portal_catalog_mode,
 portal_product_ids: orderingSetup.portal_product_ids,
 product_order_limits: orderingSetup.product_order_limits,
 product_warranties: orderingSetup.product_warranties,
 };
 const customer = await crmCustomersApi.create(payload);
 if (!sendPortalInvitation) return { customer, invitation: 'not_requested' as const };
 try {
  const name = customer.contact_person?.trim() || values.contact_person?.trim() || '';
  const email = customer.email?.trim() || values.email?.trim() || '';
  if (!name || !email) return { customer, invitation: 'failed' as const };
  await portalAccessApi.inviteCustomer(customer.id, { name, email, is_authorized_signatory: isAuthorizedSignatory });
  return { customer, invitation: 'queued' as const };
 } catch {
  return { customer, invitation: 'failed' as const };
 }
 },
 onSuccess: ({ customer, invitation }) => {
 if (invitation === 'queued') void qc.invalidateQueries({ queryKey: ['b2b', 'portal-access'] });
 reconcileRealtimeMutationResponse(
   qc,
   { entityType: 'customer', entityId: customer.id },
   ['crm', 'customers', 'detail', customer.id],
   customer,
 );
  safety.allowNavigationAfterSave();
 setCreatedCustomer(customer);
 setSearchParams({ setup: customer.id }, { replace: true });
 if (invitation === 'failed') {
  toast.error('Customer created, but the invitation failed. Retry from Portal Access.');
 } else if (invitation === 'queued') {
  toast.success('Customer created. Portal invitation queued; continue with agreement setup.');
 } else {
  toast.success('Customer saved. Continue with portal and agreement setup.');
 }
 },
 onError: (e) => {
   const responseErrors = (e as { response?: { data?: { errors?: Record<string, unknown> } } })?.response?.data?.errors;
   const firstInvalidField = responseErrors ? Object.keys(responseErrors)[0] : undefined;
   if (firstInvalidField) setCurrentStep(stepForField(firstInvalidField));
   applyServerValidationErrors(e, setError, 'Failed to create the customer.');
 },
 });
 const safety = useFormSafety({ form: methods, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader
 title={activeCustomer ? 'Set up customer' : 'New customer'}
 subtitle={activeCustomer
  ? `${activeCustomer.name} is saved. Review the saved product setup and prepare agreement documents.`
 : 'Set up customer details, billing, delivery, and agreement terms in five guided steps.'}
 backTo={activeCustomer ? `/crm/customers/${activeCustomer.id}` : '/crm/customers'}
 backLabel={activeCustomer ? 'Customer record' : 'Customers'}
 />
 <div className="mx-auto w-full max-w-screen-2xl px-4 pb-24 sm:px-5 md:pb-8">
  {activeCustomer ? (
   <CustomerAgreementSetupStep
    customer={activeCustomer}
    pendingAgreementFiles={pendingAgreementFiles}
    onFinish={() => navigate(`/crm/customers/${activeCustomer.id}`)}
   />
  ) : setupCustomerId ? (
   setupCustomerQuery.isError ? (
    <EmptyState
     icon="alert-circle"
     title="Could not reopen customer setup"
     description="The customer may have been removed or your access may have changed. Retry or return to the customer list."
     action={<Button variant="secondary" onClick={() => void setupCustomerQuery.refetch()}>Retry</Button>}
    />
   ) : <p role="status" className="px-4 py-6 text-sm text-muted">Loading customer setup…</p>
  ) : (
   <>
    <FormDraftBanner safety={safety} inset={false} />
    <FormProvider {...methods}>
      <form noValidate onSubmit={(event) => {
       event.preventDefault();
       if (currentStep < CUSTOMER_CREATE_STEPS.length - 1) {
        void goNext();
        return;
       }
       if (checkingCustomerName || duplicateCustomerName || customerNameCheckFailed) {
        setCurrentStep(0);
        return;
       }
       if (customerNameInvalid) {
        setCurrentStep(0);
        void methods.trigger('name', { shouldFocus: true });
        return;
       }
       if (isReadingAgreementPdf || !productCatalogReady || !productOrderingValid) return;
       void handleSubmit((v) => mutation.mutate({
        values: v,
        orderingSetup: productOrderingSetup,
        sendPortalInvitation: inviteToPortal && v.is_active && Boolean(v.contact_person?.trim()) && Boolean(v.email?.trim()),
        isAuthorizedSignatory: authorizedSignatory,
       }), handleFinalInvalid)();
      }}>
      <CustomerCreateStepper
       currentStep={currentStep}
       furthestStep={currentStep === 0 && (customerNameInvalid || checkingCustomerName || duplicateCustomerName || customerNameCheckFailed) ? 0 : furthestStep}
       onSelect={goToStep}
      />
      <section aria-labelledby="customer-create-step-heading" className="min-h-[20rem]">
       <div className="mb-4 border-b border-subtle pb-4">
        <p className="text-caption font-medium uppercase tracking-wide text-muted">Step {currentStep + 1} of {CUSTOMER_CREATE_STEPS.length}</p>
        <h2 id="customer-create-step-heading" ref={stepHeadingRef} tabIndex={-1} className="mt-1 text-xl font-semibold text-primary focus:outline-none">{CUSTOMER_CREATE_STEPS[currentStep].title}</h2>
        <p className="mt-1 text-sm text-secondary">{CUSTOMER_CREATE_STEPS[currentStep].description}</p>
        <p className="mt-1 text-xs text-muted">Fields marked <span className="font-semibold text-danger-fg">*</span> are required. Optional details are labeled.</p>
       </div>

       <div hidden={currentStep !== 0}>
        <CustomerForm
         section="customer"
         showFallbackWarranty={false}
         customerSetupFooter={(
          <CustomerAgreementPdfIntake
           onFilesSelected={setPendingAgreementFiles}
           onProductReferences={setProductReferences}
           onProductWarranties={setProductWarrantySuggestions}
           onProductOrderLimits={setProductOrderLimitSuggestions}
           onReadingChange={setIsReadingAgreementPdf}
          />
         )}
        />
       </div>
       {currentStep === 0 && checkingCustomerName && (
        <p role="status" className="mt-2 flex items-center gap-2 text-sm text-muted" aria-live="polite">
         <span aria-hidden="true" className="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-r-transparent" />
         Checking for an existing customer…
        </p>
       )}
       {currentStep === 0 && duplicateCustomerName && (
        <div role="alert" className="mt-3 rounded-md border border-danger/30 bg-danger/5 p-3 text-sm text-danger-fg">
         <p className="font-semibold">
          A customer with this name already exists ({customerNameCheck.data?.count} existing {customerNameCheck.data?.count === 1 ? 'record' : 'records'}).
         </p>
         <p className="mt-1">Open the existing record or enter a distinct customer name.</p>
         <ul className="mt-2 space-y-1">
          {customerNameCheck.data?.matches.map((match) => (
           <li key={match.id}>
            <Link className="font-medium underline underline-offset-2" to={`/crm/customers/${match.id}`}>
             Open {match.name}{match.code ? ` (${match.code})` : ''}
            </Link>
           </li>
          ))}
          {(customerNameCheck.data?.count ?? 0) > (customerNameCheck.data?.matches.length ?? 0) && (
           <li>And {(customerNameCheck.data?.count ?? 0) - (customerNameCheck.data?.matches.length ?? 0)} more matching records.</li>
          )}
         </ul>
        </div>
       )}
       {currentStep === 0 && customerNameCheckFailed && (
        <div role="alert" className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-primary">
         <span>Customer name could not be checked. Retry before continuing.</span>
         <Button type="button" size="sm" variant="secondary" onClick={() => void customerNameCheck.refetch()}>Retry check</Button>
        </div>
       )}
       {currentStep === 1 && <CustomerForm section="billing" showFallbackWarranty={false} />}
       {currentStep === 2 && <CustomerForm section="delivery" showFallbackWarranty={false} />}

       <div hidden={currentStep !== 3}>
        <CustomerForm section="agreement" showFallbackWarranty={false} />
        <CustomerProductOrderingSetup
         productReferences={productReferences}
         warrantySuggestions={productWarrantySuggestions}
         orderLimitSuggestions={productOrderLimitSuggestions}
         onSetupChange={setProductOrderingSetup}
         onReadyChange={setProductCatalogReady}
         onValidityChange={setProductOrderingValid}
        />
       </div>

       {currentStep === 4 && (
        <CustomerCreateReview
         values={methods.getValues() as FormValues}
         orderingSetup={productOrderingSetup}
         agreementFiles={pendingAgreementFiles}
         productReferences={productReferences}
         warrantySuggestions={productWarrantySuggestions}
         orderLimitSuggestions={productOrderLimitSuggestions}
         inviteToPortal={inviteToPortal}
         onInviteToPortalChange={setInviteToPortal}
         authorizedSignatory={authorizedSignatory}
         onAuthorizedSignatoryChange={setAuthorizedSignatory}
         onEdit={goToStep}
        />
       )}
      </section>

      <FormActions align="between" className="mt-5">
       <Button type="button" size="touch" variant="secondary" onClick={() => currentStep === 0 ? navigate('/crm/customers') : setCurrentStep((step) => Math.max(0, step - 1))}>
        {currentStep === 0 ? 'Cancel' : 'Back'}
       </Button>
       {currentStep < 4 ? (
        <Button type="submit" size="touch" variant="primary" disabled={isSubmitting || mutation.isPending || isReadingAgreementPdf || (currentStep === 0 && (checkingCustomerName || duplicateCustomerName || customerNameCheckFailed)) || (currentStep === 3 && (!productCatalogReady || !productOrderingValid))}>
         {isReadingAgreementPdf ? 'Reading PDF…' : currentStep === 0 && checkingCustomerName ? 'Checking customer name…' : currentStep === 0 && duplicateCustomerName ? 'Customer name already exists' : currentStep === 0 && customerNameCheckFailed ? 'Retry name check to continue' : currentStep === 3 && !productCatalogReady ? 'Loading product catalog…' : currentStep === 3 && !productOrderingValid ? 'Select products and configure warranties' : 'Continue'}
        </Button>
       ) : (
        <Button type="submit" size="touch" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending || isReadingAgreementPdf || duplicateCustomerName || !productCatalogReady || !productOrderingValid}>
         {mutation.isPending ? 'Creating…' : 'Create customer'}
        </Button>
       )}
      </FormActions>
     </form>
    </FormProvider>
   </>
  )}
 </div>
 </div>
 );
}
