import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LegalShell } from './LegalShell';
import { landingApi } from '@/api/landing';

export default function CookiePolicyPage() {
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const email = contact?.company_email ?? contact?.sales_email ?? '';

  return (
    <LegalShell title="Cookie Policy" path="/cookies">
      <p>
        This site uses the smallest possible number of cookies. We do not use advertising,
        profiling or analytics cookies, and we do not embed third-party tracking.
      </p>

      <h2>Cookies we set</h2>
      <ul>
        <li>
          <strong>Session cookie</strong> (essential) — keeps you signed in to the staff, customer
          or supplier area. It is HTTP-only, secure, and expires after 30 minutes of inactivity.
          It is not set for visitors who are not signed in.
        </li>
        <li>
          <strong>XSRF-TOKEN</strong> (essential) — a cross-site request forgery token issued before
          a form is submitted, so we can verify the request came from this site. It is readable by
          script by design and is not used for tracking.
        </li>
      </ul>

      <h2>Your cookie choice</h2>
      <p>
        When you first visit, we ask you to accept or decline cookies. Your answer is stored in your
        browser&rsquo;s local storage under the key <code>ogami-cookie-consent</code> so we do not
        ask again. It is a preference record, not a tracking identifier, and clearing it makes the
        banner reappear. Because all cookies we set are essential to running the site, declining
        does not disable them.
      </p>

      <h2>Map tiles</h2>
      <p>
        The location map on our home page loads map images from OpenStreetMap&rsquo;s tile servers.
        That request discloses your IP address and the map area you view to OpenStreetMap, as
        described in our <Link to="/privacy">Privacy Policy</Link>.
      </p>

      <h2>More information</h2>
      <p>
        For anything else about how we handle personal data, see the{' '}
        <Link to="/privacy">Privacy Policy</Link>, or contact us at{' '}
        <a href={email ? `mailto:${email}` : undefined}>{email || 'our contact address'}</a>.
      </p>
    </LegalShell>
  );
}
