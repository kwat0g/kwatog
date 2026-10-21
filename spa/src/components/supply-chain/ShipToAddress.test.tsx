import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ShipToAddress } from './ShipToAddress';

describe('ShipToAddress', () => {
  it('makes the address itself a Google Maps directions link', () => {
    render(
      <ShipToAddress
        address="Toyota Plant, Santa Rosa, Laguna"
        contactPerson="Ana Reyes"
        contactPhone="049-500-1234"
      />,
    );

    const mapLink = screen.getByRole('link', {
      name: /open driving directions to toyota plant, santa rosa, laguna/i,
    });
    expect(mapLink).toHaveAttribute(
      'href',
      'https://www.google.com/maps/dir/?api=1&destination=Toyota%20Plant%2C%20Santa%20Rosa%2C%20Laguna',
    );
    expect(mapLink).toHaveAttribute('target', '_blank');
    expect(mapLink).toHaveAttribute('rel', 'noreferrer');
    expect(screen.getByText('Toyota Plant, Santa Rosa, Laguna')).toBeInTheDocument();
  });

  it('renders the receiving contact and a dialable phone link', () => {
    render(<ShipToAddress address="Gate 3" contactPerson="Ana Reyes" contactPhone="049-500-1234" />);

    expect(screen.getByText('Ana Reyes')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /call the receiving contact/i })).toHaveAttribute(
      'href',
      'tel:0495001234',
    );
  });

  it('shows the contact without a map link when only the contact is on file', () => {
    render(<ShipToAddress address={null} contactPerson="Ana Reyes" />);

    expect(screen.getByText('Ana Reyes')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /google maps/i })).not.toBeInTheDocument();
    expect(screen.getByText(/no delivery address on file/i)).toBeInTheDocument();
  });

  it('explains what to fix when the customer has no ship-to at all', () => {
    render(<ShipToAddress address="  " emptyHint="This customer has no address yet." />);

    expect(screen.getByText('This customer has no address yet.')).toBeInTheDocument();
    expect(screen.getByText(/add the delivery address/i)).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('renders a customer heading once', () => {
    render(<ShipToAddress address="Gate 3" heading="Ogami Trading" />);
    expect(screen.getByText('Ogami Trading')).toBeInTheDocument();
  });
});
