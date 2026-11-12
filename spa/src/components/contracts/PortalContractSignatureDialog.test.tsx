import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PortalContractSignatureDialog } from './PortalContractSignatureDialog';

describe('PortalContractSignatureDialog', () => {
  it('requires typed-name confirmation and an explicit agreement before enabling signing', () => {
    const onConfirm = vi.fn();
    render(
      <PortalContractSignatureDialog
        isOpen
        documentName="Honda supply terms.pdf"
        signerName="Mika Sato"
        onCancel={vi.fn()}
        onConfirm={onConfirm}
      />,
    );

    const signButton = screen.getByRole('button', { name: 'Sign agreement' });
    expect(signButton).toHaveProperty('disabled', true);
    fireEvent.change(screen.getByRole('textbox', { name: 'Type your full name to sign' }), { target: { value: 'Mika Sato' } });
    fireEvent.click(screen.getByRole('checkbox', { name: /i agree to sign/i }));
    expect(signButton).toHaveProperty('disabled', false);
    fireEvent.click(signButton);

    expect(onConfirm).toHaveBeenCalledWith('Mika Sato');
  });
});
