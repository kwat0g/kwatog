import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';

interface PortalContractSignatureDialogProps {
  isOpen: boolean;
  documentName: string;
  signerName: string;
  isSubmitting?: boolean;
  onCancel: () => void;
  onConfirm: (typedName: string) => void;
}

export function PortalContractSignatureDialog({
  isOpen,
  documentName,
  signerName,
  isSubmitting = false,
  onCancel,
  onConfirm,
}: PortalContractSignatureDialogProps) {
  const [typedName, setTypedName] = useState('');
  const [agreed, setAgreed] = useState(false);
  useEffect(() => {
    if (!isOpen) {
      setTypedName('');
      setAgreed(false);
    }
  }, [isOpen]);

  const canSign = typedName.trim() === signerName.trim() && typedName.trim().length > 0 && agreed && !isSubmitting;

  return (
    <Modal isOpen={isOpen} onClose={onCancel} title="Sign agreement" size="md">
      <div className="space-y-4">
        <p className="text-sm text-secondary">
          You are signing <span className="font-medium text-primary">{documentName}</span> for your organization. Your typed name and signing time will be recorded with this exact PDF.
        </p>
        <Input
          label="Type your full name to sign"
          required
          autoComplete="name"
          value={typedName}
          onChange={(event) => setTypedName(event.target.value)}
          maxLength={200}
          helper={`Enter your portal account name exactly: ${signerName}`}
        />
        <Checkbox
          label={<span>I agree to sign this agreement electronically on behalf of my organization.</span>}
          checked={agreed}
          onChange={(event) => setAgreed(event.target.checked)}
        />
      </div>
      <ModalFooter>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting}>Cancel</Button>
        <Button type="button" variant="primary" onClick={() => onConfirm(typedName.trim())} disabled={!canSign} loading={isSubmitting}>
          Sign agreement
        </Button>
      </ModalFooter>
    </Modal>
  );
}
