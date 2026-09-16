import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LegalShell } from './LegalShell';
import { landingApi } from '@/api/landing';

export default function TermsPage() {
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const legalName = contact?.legal_name ?? 'the company';
  const address = contact?.address ?? '';
  const email = contact?.company_email ?? contact?.sales_email ?? '';

  return (
    <LegalShell title="Terms & Conditions" path="/terms">
      <p>
        These terms govern your use of this website and the enterprise system operated by{' '}
        {legalName}. By using the site you agree to them. If you do not agree, please do not use the
        site.
      </p>

      <h2>Use of the site</h2>
      <p>
        You may use the public pages for lawful purposes only. The staff, customer and supplier
        areas are restricted to authorised users, and you must keep your credentials confidential
        and not share access. You must not attempt to gain unauthorised access, interfere with the
        service, or use it in a way that could damage or overload it.
      </p>

      <h2>Content and accuracy</h2>
      <p>
        The information on this site is provided for general information. Product specifications,
        capabilities, certifications and availability may change and are not a binding offer. A
        supply relationship is governed only by the written agreement between us and the customer
        or supplier.
      </p>

      <h2>Intellectual property</h2>
      <p>
        The site, its content, and the marks and logos shown are owned by {legalName} or its
        licensors and may not be copied or reused without permission.
      </p>

      <h2>Third-party links and services</h2>
      <p>
        The site may link to third-party services (for example map providers). We are not
        responsible for their content or practices; their own terms and privacy policies apply.
      </p>

      <h2>Disclaimer and liability</h2>
      <p>
        The site is provided &ldquo;as is&rdquo;. To the extent permitted by law, we exclude
        warranties relating to the site and are not liable for indirect or consequential loss
        arising from its use. Nothing in these terms limits liability that cannot lawfully be
        limited.
      </p>

      <h2>Privacy</h2>
      <p>
        Our handling of personal data is described in the{' '}
        <Link to="/privacy">Privacy Policy</Link> and the <Link to="/cookies">Cookie Policy</Link>.
      </p>

      <h2>Governing law</h2>
      <p>
        These terms are governed by the laws of the Republic of the Philippines, and the courts of
        the Philippines have exclusive jurisdiction over any dispute.
      </p>

      <h2>Contact</h2>
      <p>
        {legalName}
        {address ? `, ${address}` : ''}
        {email ? (
          <>
            {' '}
            — <a href={`mailto:${email}`}>{email}</a>
          </>
        ) : null}
      </p>
    </LegalShell>
  );
}
