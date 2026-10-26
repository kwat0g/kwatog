import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DetailLayout, FormGrid } from '../ContentLayouts';
import { PageContainer, PageShell } from '../PageShell';

describe('shared page layouts', () => {
  it('keeps shell modes on one guttered page root', () => {
    const { container, rerender } = render(<PageShell variant="narrow" viewport>Content</PageShell>);
    const shell = container.querySelector('[data-layout="page-shell"]');
    expect(shell).toHaveAttribute('data-layout', 'page-shell');
    expect(shell).toHaveClass('page-shell', 'page-shell--narrow', 'page-shell--viewport');

    rerender(<PageContainer variant="fluid">Container</PageContainer>);
    expect(container.querySelector('[data-layout="page-container"]')).toHaveTextContent('Container');
  });

  it('sets responsive form column intent and renders detail aside after main content', () => {
    const { container } = render(
      <>
        <FormGrid columns={4}><label>Item name</label><label>Quantity</label></FormGrid>
        <DetailLayout aside={<span>Summary</span>}><p>Details</p></DetailLayout>
      </>,
    );

    expect(container.querySelector('[data-layout="form-grid"]')).toHaveAttribute('data-columns', '4');
    const detail = container.querySelector('[data-layout="detail-layout"]');
    expect(detail).toHaveClass('detail-layout--with-aside');
    expect(detail?.firstElementChild).toHaveTextContent('Details');
    expect(detail?.lastElementChild).toHaveTextContent('Summary');
  });
});
