import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LegalShell } from './LegalShell';
import { landingApi } from '@/api/landing';

export default function PrivacyPolicyPage() {
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const legalName = contact?.legal_name ?? 'the company';
  const address = contact?.address ?? '';
  const email = contact?.company_email ?? contact?.sales_email ?? '';

  return (
    <LegalShell title="Privacy Policy" path="/privacy">
      <p>
        {legalName} (&ldquo;we&rdquo;, &ldquo;us&rdquo;) operates this website and the enterprise
        system behind it. This policy explains what personal data we collect, why, and the choices
        you have. It covers the public website, the careers page, and the staff, customer and
        supplier areas of the system.
      </p>

      <h2>Information we collect</h2>
      <p>Through the public website:</p>
      <ul>
        <li>
          <strong>Contact enquiries</strong> — your name, company (optional), email address, phone
          number (optional) and message. We also record the source IP address and browser
          user-agent to help prevent abuse.
        </li>
        <li>
          <strong>Newsletter</strong> — your email address and the source IP address.
        </li>
        <li>
          <strong>Job applications</strong> — your name, email address, phone number, résumé file
          and an optional cover letter.
        </li>
      </ul>
      <p>
        If you use the staff, customer or supplier areas, we process the business and employment
        records needed to operate those services — for example contact details and transaction
        records, and, for employees, statutory and payroll identifiers. Sensitive identifiers (SSS,
        PhilHealth, Pag-IBIG, TIN and bank account numbers) are encrypted at rest.
      </p>

      <h2>How we use your information</h2>
      <ul>
        <li>to respond to your enquiry or request;</li>
        <li>to assess job applications and communicate with candidates;</li>
        <li>to send the newsletter you asked to receive;</li>
        <li>to operate the enterprise system and the customer/supplier portals;</li>
        <li>to keep the service secure and to prevent abuse.</li>
      </ul>

      <h2>Consent</h2>
      <p>
        We process personal data submitted through the public forms on the basis of your consent.
        You give that consent by ticking the checkbox on the form, and we record the time of
        acceptance alongside your submission. You can withdraw consent at any time by contacting us.
      </p>

      <h2>Cookies</h2>
      <p>
        We use only the cookies strictly necessary to run the site and keep a signed-in session
        secure. We do not use advertising or analytics cookies. See our{' '}
        <Link to="/cookies">Cookie Policy</Link> for the full list.
      </p>

      <h2>Sharing</h2>
      <p>We do not sell personal data. We share it only with service providers who process it on our behalf, including:</p>
      <ul>
        <li>
          our email delivery provider, used to send transactional email (such as your enquiry
          confirmation or application updates); and
        </li>
        <li>
          OpenStreetMap, whose map tiles are loaded on the location section of our website (this
          discloses your IP address and the map area you view to the tile servers).
        </li>
      </ul>

      <h2>Retention</h2>
      <ul>
        <li>
          <strong>Contact enquiries</strong> — kept while we handle your enquiry and any related
          follow-up, then removed on request.
        </li>
        <li>
          <strong>Newsletter</strong> — kept until you unsubscribe or ask us to delete it.
        </li>
        <li>
          <strong>Job applications</strong> — kept for the recruitment process and a reasonable
          period afterwards, then removed on request.
        </li>
        <li>
          <strong>System and portal records</strong> — kept for as long as needed for the business
          relationship and to meet statutory record-keeping requirements; audit logs are archived.
        </li>
      </ul>

      <h2>Your rights</h2>
      <p>
        You may ask to access, correct or delete your personal data, and you may object to or
        withdraw your consent. To make a request, email us at{' '}
        <a href={email ? `mailto:${email}` : undefined}>{email || 'our contact address'}</a>. We will
        respond within a reasonable period. You also have the right to lodge a complaint with the
        National Privacy Commission.
      </p>

      <h2>Security</h2>
      <p>
        Access to the system is controlled by role-based permissions, sensitive identifiers are
        encrypted, traffic is served over HTTPS, and authentication and financial activity is
        logged.
      </p>

      <h2>Changes to this policy</h2>
      <p>We may update this policy from time to time. The date at the top of this page will change when we do.</p>

      <h2>Contact us</h2>
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
