import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import {
  contractRecordFields,
  type ContractTemplateSubmission,
  type DiscoveredContractField,
  type PortalContractRealm,
} from './contractTemplateFields';

interface ContractTemplateFormProps {
  realm: PortalContractRealm;
  onInspect: (file: File, realm: PortalContractRealm) => Promise<DiscoveredContractField[]>;
  onSubmit: (submission: ContractTemplateSubmission) => void;
  previewValues?: Record<string, string | null | undefined>;
  isSubmitting?: boolean;
  onCancel?: () => void;
}

export function ContractTemplateForm({
  realm,
  onInspect,
  onSubmit,
  previewValues = {},
  isSubmitting = false,
  onCancel,
}: ContractTemplateFormProps) {
  const [name, setName] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [fields, setFields] = useState<DiscoveredContractField[] | null>(null);
  const [mappings, setMappings] = useState<Record<string, string>>({});
  const [requiredFields, setRequiredFields] = useState<string[]>([]);
  const [inspectError, setInspectError] = useState<string | null>(null);
  const [isInspecting, setIsInspecting] = useState(false);
  const allowedFields = contractRecordFields(realm);

  const inspect = async () => {
    if (!file) return;
    setIsInspecting(true);
    setInspectError(null);
    try {
      const detected = await onInspect(file, realm);
      setFields(detected);
      setMappings({});
      setRequiredFields([]);
    } catch {
      setInspectError('Could not read this PDF. Check the file and try again.');
    } finally {
      setIsInspecting(false);
    }
  };

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!file || fields === null || !name.trim()) return;
    onSubmit({ name: name.trim(), realm, file, field_mappings: mappings, required_fields: requiredFields });
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <Input
        label="Template name"
        required
        maxLength={160}
        value={name}
        onChange={(event) => setName(event.target.value)}
      />
      <div className="space-y-2">
        <label htmlFor="contract-template-file" className="block text-sm font-medium text-primary">PDF template</label>
        <input
          id="contract-template-file"
          type="file"
          accept="application/pdf,.pdf"
          required
          className="block w-full text-sm text-secondary file:mr-3 file:rounded-md file:border file:border-default file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
          onChange={(event) => {
            setFile(event.target.files?.[0] ?? null);
            setFields(null);
            setMappings({});
            setRequiredFields([]);
            setInspectError(null);
          }}
        />
        <p className="text-xs text-muted">PDFs with fillable fields can be mapped to this account’s saved details. Other PDFs can be attached as-is.</p>
        <Button type="button" size="sm" variant="secondary" onClick={inspect} loading={isInspecting} disabled={!file || isInspecting}>
          Read PDF fields
        </Button>
        {inspectError && <p role="alert" className="text-sm text-danger">{inspectError}</p>}
      </div>

      {fields !== null && fields.length === 0 && (
        <div className="rounded-md border border-default bg-surface p-3 text-sm text-secondary">
          <p className="font-medium text-primary">No fillable fields were found</p>
          <p className="mt-1">Attach this PDF as-is. It can still be downloaded and signed in the portal.</p>
        </div>
      )}

      {fields !== null && fields.length > 0 && (
        <div className="space-y-3">
          <h3 className="text-sm font-semibold text-primary">Map PDF fields</h3>
          <p className="text-xs text-muted">Mapped values come from the selected account. Review the generated PDF before issuing it.</p>
          <div className="divide-y divide-subtle border-y border-default">
            {fields.map((field) => {
              const selectedKey = mappings[field.name] ?? '';
              const mappedLabel = allowedFields.find((candidate) => candidate.key === selectedKey)?.label;
              return (
                <div key={field.name} className="grid gap-3 py-3 md:grid-cols-[minmax(12rem,1fr)_minmax(14rem,1.2fr)_minmax(10rem,0.8fr)] md:items-start">
                  <div className="min-w-0">
                    <p className="break-words text-sm font-medium text-primary">{field.name}</p>
                    <p className="text-xs text-muted">{field.type ?? 'PDF field'}</p>
                  </div>
                  <Select
                    label={`Map “${field.name}” to`}
                    value={selectedKey}
                    onChange={(event) => {
                      const key = event.target.value;
                      setMappings((current) => {
                        const next = { ...current };
                        if (key) next[field.name] = key;
                        else delete next[field.name];
                        return next;
                      });
                      if (!key) setRequiredFields((current) => current.filter((required) => required !== field.name));
                    }}
                  >
                    <option value="">Leave unchanged</option>
                    {allowedFields.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}
                  </Select>
                  <div className="space-y-2">
                    <Checkbox
                      label="Required before issue"
                      checked={requiredFields.includes(field.name)}
                      disabled={!selectedKey}
                      onChange={(event) => setRequiredFields((current) => event.target.checked
                        ? [...current, field.name]
                        : current.filter((required) => required !== field.name))}
                    />
                    {mappedLabel && (
                      <p className="break-words text-xs text-muted">
                        Preview: {previewValues[selectedKey] || `Saved ${mappedLabel.toLowerCase()} will appear here`}
                      </p>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      <div className="flex flex-wrap justify-end gap-2 border-t border-default pt-4">
        {onCancel && <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting}>Cancel</Button>}
        <Button type="submit" variant="primary" loading={isSubmitting} disabled={!file || fields === null || !name.trim() || isSubmitting}>
          Save contract template
        </Button>
      </div>
    </form>
  );
}
