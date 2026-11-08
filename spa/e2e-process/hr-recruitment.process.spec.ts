import { randomUUID } from 'node:crypto';
import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep, type JourneyContext } from './process-runner';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { readClock } from './helpers/clock';
import { assertRecipientsExactly } from './helpers/process-invariants';
import { monitorPage } from './helpers/page-diagnostics';
import {
  findMailpitMessagesFor,
  getMailpitMessage,
  type MailpitSummary,
} from './helpers/mailpit-reader';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;

type PostingSnapshot = {
  id: number;
  posting_number: string;
  status: string;
  slots: number;
  posted_at: string | null;
  created_by_email: string;
  application_count: number;
  audit_entries: Array<{ action: string; actor_email: string | null; status: string | null }>;
};

type ApplicationSnapshot = {
  id: number;
  application_number: string;
  tracking_code: string;
  stage: string;
  email: string;
  resume_original_name: string;
  consent_at: string | null;
  converted_employee_id: number | null;
  posting_status: string;
  events: Array<{
    event_type: string;
    from_stage: string | null;
    to_stage: string | null;
    actor_email: string | null;
  }>;
  interviews: Array<{
    interviewer_name: string;
    location: string | null;
    outcome: string | null;
    created_by_email: string;
  }>;
};

type NotificationSnapshot = {
  email: string;
  role_slug: string;
  link_to: string;
  read_at: string | null;
};

const plannedSteps = [
  { name: 'HR creates a run-scoped draft posting in Recruitment', actor: 'hr_officer', uiPath: 'Dashboard → Human Resources → Recruitment → New Posting → Create Posting', expectedStatus: 'draft; one slot; unique posting number; HR is creator' },
  { name: 'HR publishes the posting', actor: 'hr_officer', uiPath: 'Posting detail → Publish', expectedStatus: 'open with posted timestamp; public listing can display the position' },
  { name: 'Candidate finds the live posting and applies through Careers', actor: 'public applicant', uiPath: 'Landing page → Footer company → Careers → posting → Apply → Submit Application', expectedStatus: 'new application with consent, resume, unique tracking code, confirmation email, and HR notification' },
  { name: 'HR follows the notification hand-off and an administrator opens the same application as observer', actor: 'hr_officer; system_admin observer', uiPath: 'Dashboard → Work → Notifications → New Job Application; observer uses Recruitment → Applications', expectedStatus: 'HR opens the linked New application; intended recipients only; observer sees the same record' },
  { name: 'HR advances the application to Screening', actor: 'hr_officer; system_admin observer', uiPath: 'Application detail → Move to Screening → Advance', expectedStatus: 'screening; HR event recorded; observer updates live; candidate receives status email' },
  { name: 'HR schedules an interview as the Screening-to-Interview hand-off', actor: 'hr_officer; system_admin observer', uiPath: 'Application detail → Move to Interview → Schedule & Move to Interview', expectedStatus: 'interview with a future Manila-local schedule; observer updates live; candidate receives interview details' },
  { name: 'HR records the interview outcome as Passed', actor: 'hr_officer; system_admin observer', uiPath: 'Application detail → Interviews → Outcome → Passed', expectedStatus: 'interview remains current; passed interview unlocks Move to Offer' },
  { name: 'HR advances the passed candidate to Offer', actor: 'hr_officer; system_admin observer', uiPath: 'Application detail → Move to Offer → Advance', expectedStatus: 'offer; HR event recorded; observer updates live; candidate receives status email' },
  { name: 'HR records the accepted offer as Hired', actor: 'hr_officer; system_admin observer', uiPath: 'Application detail → Move to Hired → Advance', expectedStatus: 'hired; the one-slot posting becomes filled; employee conversion remains a separate process' },
  { name: 'Candidate follows the tracking link and confirms the final hired state', actor: 'public applicant', uiPath: 'Application Submitted → the tracking page → Track', expectedStatus: 'public tracker reports Hired for the run-scoped posting and tracking code' },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const found = actors.find((candidate) => candidate.actor === actor);
  if (!found) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return found;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

function contextValue<T>(context: JourneyContext, key: string): T {
  const value = context.values[key];
  if (value === undefined) throw new Error(`Journey context is missing ${key}.`);
  return value as T;
}

async function openSidebarItem(
  session: ActorPage,
  section: string,
  href: string,
  label: string,
): Promise<void> {
  const nav = session.page.getByRole('navigation', { name: 'Modules' });
  const target = nav.locator(`a[href="${href}"]`);
  await expect(target, `${label} should be present in this role's navigation`).toHaveCount(1);
  if (!(await target.isVisible())) {
    const module = session.page.locator(`[data-module="${section}"]`);
    const toggle = module.getByRole('button');
    await expect(toggle, `${section} should be expandable`).toHaveCount(1);
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  }
  await expect(target).toBeVisible();
  await target.click();
}

function statusBadge(page: ActorPage['page'], status: string) {
  return page.locator('[data-layout="page-header"]').getByText(status, { exact: true });
}

function manilaDateTimeInput(instant: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(instant));
  const value = (type: string) => parts.find((part) => part.type === type)?.value;
  const year = value('year');
  const month = value('month');
  const day = value('day');
  const hour = value('hour');
  const minute = value('minute');
  if (!year || !month || !day || !hour || !minute) throw new Error(`Unable to format Manila interview time from ${instant}.`);
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

async function postingSnapshot(title: string): Promise<PostingSnapshot[]> {
  return readOnlyDatabaseJson<PostingSnapshot[]>(`
    SELECT posting.id,
           posting.posting_number,
           posting.status,
           posting.slots,
           posting.posted_at::text AS posted_at,
           creator.email AS created_by_email,
           (SELECT COUNT(*)::int FROM job_applications application WHERE application.job_posting_id = posting.id) AS application_count,
           COALESCE((
             SELECT json_agg(json_build_object(
               'action', audit.action,
               'actor_email', actor.email,
               'status', audit.new_values->>'status'
             ) ORDER BY audit.id)
               FROM audit_logs audit
               LEFT JOIN users actor ON actor.id = audit.user_id
              WHERE audit.model_type ILIKE '%JobPosting%'
                AND audit.model_id = posting.id
           ), '[]'::json) AS audit_entries
      FROM job_postings posting
      JOIN users creator ON creator.id = posting.created_by
     WHERE posting.title = ${sqlText(title)}
  `);
}

async function applicationSnapshot(email: string): Promise<ApplicationSnapshot[]> {
  return readOnlyDatabaseJson<ApplicationSnapshot[]>(`
    SELECT application.id,
           application.application_number,
           application.tracking_code,
           application.stage,
           application.email,
           application.resume_original_name,
           application.consent_at::text AS consent_at,
           application.converted_employee_id,
           posting.status AS posting_status,
           COALESCE((
             SELECT json_agg(json_build_object(
               'event_type', event.event_type,
               'from_stage', event.from_stage,
               'to_stage', event.to_stage,
               'actor_email', actor.email
             ) ORDER BY event.id)
               FROM recruitment_application_events event
               LEFT JOIN users actor ON actor.id = event.actor_user_id
              WHERE event.job_application_id = application.id
           ), '[]'::json) AS events,
           COALESCE((
             SELECT json_agg(json_build_object(
               'interviewer_name', interview.interviewer_name,
               'location', interview.location,
               'outcome', interview.outcome,
               'created_by_email', creator.email
             ) ORDER BY interview.id)
               FROM application_interviews interview
               JOIN users creator ON creator.id = interview.created_by
              WHERE interview.job_application_id = application.id
           ), '[]'::json) AS interviews
      FROM job_applications application
      JOIN job_postings posting ON posting.id = application.job_posting_id
     WHERE application.email = ${sqlText(email)}
  `);
}

async function applicationNotifications(hashId: string): Promise<NotificationSnapshot[]> {
  return readOnlyDatabaseJson<NotificationSnapshot[]>(`
    SELECT recipient.email,
           role.slug AS role_slug,
           notice.data->>'link_to' AS link_to,
           notice.read_at::text AS read_at
      FROM notifications notice
      JOIN users recipient
        ON notice.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
       AND recipient.id = notice.notifiable_id
      JOIN roles role ON role.id = recipient.role_id
     WHERE notice.type = 'recruitment.new_application'
       AND notice.data->>'entity_id' = ${sqlText(hashId)}
  `);
}

async function waitForApplication(email: string, expectedStage: string): Promise<ApplicationSnapshot> {
  let rows: ApplicationSnapshot[] = [];
  await expect.poll(async () => {
    rows = await applicationSnapshot(email);
    return rows[0]?.stage ?? 'missing';
  }, { timeout: 15_000, message: `candidate application should reach ${expectedStage}` }).toBe(expectedStage);
  if (!rows[0]) throw new Error(`Application for ${email} disappeared from the E2E database.`);
  return rows[0];
}

async function newCandidateMail(
  email: string,
  excludedIds: Set<string>,
  subjectFragment: string,
  expectedBody: string[],
): Promise<MailpitSummary> {
  let matching: MailpitSummary[] = [];
  await expect.poll(async () => {
    matching = (await findMailpitMessagesFor(email)).filter(
      (message) => !excludedIds.has(message.ID) && message.Subject.includes(subjectFragment),
    );
    return matching.length;
  }, { timeout: 25_000, message: `Mailpit should receive ${subjectFragment} for ${email}` }).toBeGreaterThan(0);

  const message = matching.sort((left, right) => right.Created.localeCompare(left.Created))[0];
  assertRecipientsExactly(message.To.map((recipient) => recipient.Address), [email]);
  const contents = JSON.stringify(await getMailpitMessage(message.ID));
  for (const expected of expectedBody) expect(contents, `${subjectFragment} email should contain ${expected}`).toContain(expected);
  return message;
}

async function existingMailIds(email: string): Promise<Set<string>> {
  return new Set((await findMailpitMessagesFor(email)).map((message) => message.ID));
}

async function assertStageEvent(email: string, from: string, to: string, actorEmail: string): Promise<void> {
  const application = await waitForApplication(email, to);
  const event = application.events.find((entry) => entry.event_type === 'stage.advanced' && entry.from_stage === from && entry.to_stage === to);
  expect(event, `stage event ${from} → ${to} should be persisted`).toBeTruthy();
  expect(event?.actor_email).toBe(actorEmail);
}

async function assertLiveStage(observer: ActorPage, expected: string, availableAction?: string): Promise<void> {
  await expect(statusBadge(observer.page, expected), `the open observer record should update to ${expected} without refresh`).toBeVisible();
  if (availableAction) await expect(observer.page.getByRole('button', { name: availableAction, exact: true })).toBeEnabled();
}

test('HR02-HAPPY — candidate applies through the posting lifecycle and HR hires through interview and offer', async ({ browser, request }, testInfo) => {
  test.setTimeout(240_000);

  const actors = await loadActors();
  const hrActor = actorByName(actors, 'hr_officer');
  const adminActor = actorByName(actors, 'system_admin');
  const clockNow = await readClock(request);
  const runId = randomUUID().replaceAll('-', '').slice(0, 12).toLowerCase();
  const postingTitle = `E2E ${runId} Quality Engineer`;
  const requirement = `Run ${runId}: injection molding quality experience`;
  const candidateName = `E2E Candidate ${runId}`;
  const firstName = 'E2E';
  const lastName = `Candidate ${runId}`;
  const candidateEmail = `candidate-${runId}@candidate.test`;
  const phone = '09171234567';
  const resumeName = `resume-${runId}.pdf`;
  const interviewerName = `E2E Interviewer ${runId}`;
  const interviewLocation = `Quality room ${runId}`;
  const interviewAt = manilaDateTimeInput(new Date(Date.parse(clockNow) + 48 * 60 * 60 * 1000).toISOString());
  const journey = createJourneyContext('HR02-HAPPY');
  journey.values.runId = runId;
  journey.values.postingTitle = postingTitle;
  journey.values.candidateEmail = candidateEmail;
  journey.values.candidateName = candidateName;

  const hr = await actorPage(browser, hrActor.actor, { timezoneId: 'Asia/Manila' });
  const observer = await actorPage(browser, adminActor.actor, { timezoneId: 'Asia/Manila' });
  const applicantContext = await browser.newContext({
    baseURL: process.env.E2E_BASE_URL,
    ignoreHTTPSErrors: true,
    timezoneId: 'Asia/Manila',
  });
  const applicantPage = await applicantContext.newPage();
  const applicantDiagnostics = await monitorPage(applicantPage);
  const primaryMailIdsBefore = await existingMailIds(candidateEmail);
  let primaryError: unknown;

  try {
    await hr.context.clock.setFixedTime(new Date(clockNow));
    await observer.context.clock.setFixedTime(new Date(clockNow));
    await applicantContext.clock.setFixedTime(new Date(clockNow));

    await runProcessStep({
      ...plannedSteps[0],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await hr.page.goto('/dashboard');
        await expect(hr.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
        await openSidebarItem(hr, 'Human Resources', '/hr/recruitment', 'Recruitment');
        await expect(hr.page.getByRole('heading', { name: 'Recruitment', exact: true })).toBeVisible();
        await hr.page.locator('[data-layout="page-header"]').getByRole('button', { name: 'New Posting', exact: true }).click();
        await expect(hr.page.getByRole('heading', { name: 'Create Job Posting', exact: true })).toBeVisible();
        await hr.page.getByLabel('Job Title').fill(postingTitle);
        await hr.page.getByRole('combobox', { name: 'Department' }).click();
        await hr.page.getByRole('option', { name: 'Human Resources', exact: true }).click();
        await hr.page.getByLabel('Employment Type').selectOption('regular');
        await hr.page.getByLabel('Slots').fill('1');
        await hr.page.getByPlaceholder('Describe the role, responsibilities, and day-to-day work...').fill(`Quality role for the E2E recruitment process ${runId}.`);
        await hr.page.getByPlaceholder('Type and press Enter to add').fill(requirement);
        await hr.page.getByRole('button', { name: 'Add', exact: true }).click();
        await hr.page.getByRole('button', { name: 'Create Posting', exact: true }).click();
        await expect(hr.page.getByText(postingTitle, { exact: true })).toBeVisible();
        await expect(statusBadge(hr.page, 'Draft')).toBeVisible();
      },
      verify: async () => {
        const rows = await postingSnapshot(postingTitle);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('draft');
        expect(rows[0].slots).toBe(1);
        expect(rows[0].created_by_email).toBe(hrActor.email);
        expect(rows[0].posting_number).toMatch(/.+/);
        expect(rows[0].audit_entries.some((entry) => entry.actor_email === hrActor.email && entry.action === 'created')).toBe(true);
        journey.values.postingNumber = rows[0].posting_number;
        journey.values.postingId = rows[0].id;
      },
      assertInvariants: async () => hr.diagnostics.assertClean('HR draft posting creation'),
    });

    await runProcessStep({
      ...plannedSteps[1],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await expect(hr.page.getByRole('button', { name: 'Publish', exact: true })).toBeEnabled();
        await hr.page.getByRole('button', { name: 'Publish', exact: true }).click();
      },
      verify: async () => {
        await expect(statusBadge(hr.page, 'Open')).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Publish', exact: true })).toHaveCount(0);
        await expect(hr.page.locator('[data-layout="page-header"]').getByRole('button', { name: 'Close', exact: true })).toBeEnabled();
        const rows = await postingSnapshot(postingTitle);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('open');
        expect(rows[0].posted_at).toBeTruthy();
        expect(rows[0].audit_entries.some((entry) => entry.actor_email === hrActor.email && entry.status === 'open')).toBe(true);
      },
      assertInvariants: async () => hr.diagnostics.assertClean('HR posting publication'),
    });

    await runProcessStep({
      ...plannedSteps[2],
      page: applicantPage,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await applicantPage.goto('/');
        const careersLink = applicantPage.getByRole('navigation', { name: 'Footer company' }).getByRole('link', { name: 'Careers', exact: true });
        await careersLink.scrollIntoViewIfNeeded();
        await careersLink.click();
        await expect(applicantPage).toHaveURL(/\/careers$/);
        await applicantPage.getByRole('link', { name: postingTitle, exact: false }).click();
        await expect(applicantPage.getByRole('heading', { name: postingTitle, exact: true })).toBeVisible();
        await applicantPage.getByLabel('First Name *').fill(firstName);
        await applicantPage.getByLabel('Last Name *').fill(lastName);
        await applicantPage.getByLabel('Email *').fill(candidateEmail);
        await applicantPage.getByLabel('Phone *').fill(phone);
        await applicantPage.getByLabel('Cover Letter').fill(`I am applying for the run ${runId} Quality Engineer position.`);
        await applicantPage.locator('#resume-upload').setInputFiles({
          name: resumeName,
          mimeType: 'application/pdf',
          buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'),
        });
        await applicantPage.getByRole('checkbox', {
          name: /We use your application only to assess your candidacy/,
        }).check();
        const responsePromise = applicantPage.waitForResponse((response) => {
          const url = new URL(response.url());
          return response.request().method() === 'POST' && url.pathname.endsWith('/apply');
        });
        await applicantPage.getByRole('button', { name: 'Submit Application', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'the public application form should report a successful creation').toBe(201);
        await expect(applicantPage.getByRole('heading', { name: 'Application Submitted!', exact: true })).toBeVisible();
        const trackingCode = (await applicantPage.getByText(/^RCT-[A-Z0-9]{6}$/, { exact: true }).textContent())?.trim();
        expect(trackingCode).toMatch(/^RCT-[A-Z0-9]{6}$/);
        journey.values.trackingCode = trackingCode;
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'new');
        expect(application.email).toBe(candidateEmail);
        expect(application.tracking_code).toBe(contextValue<string>(journey, 'trackingCode'));
        expect(application.consent_at).toBeTruthy();
        expect(application.resume_original_name).toBe(resumeName);
        expect(application.application_number).toMatch(/.+/);
        journey.values.applicationNumber = application.application_number;
        const posting = await postingSnapshot(postingTitle);
        expect(posting).toHaveLength(1);
        expect(posting[0].status).toBe('open');
        expect(posting[0].application_count).toBe(1);
        const mail = await newCandidateMail(candidateEmail, primaryMailIdsBefore, `Application received: ${postingTitle}`, [candidateName, postingTitle, application.tracking_code, '/careers/track']);
        journey.values.applicationReceivedMailId = mail.ID;
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR view during public application creation');
        applicantDiagnostics.assertClean('candidate application submission');
      },
    });

    await runProcessStep({
      ...plannedSteps[3],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await openSidebarItem(hr, 'Overview', '/notifications', 'Notifications');
        await expect(hr.page.getByRole('heading', { name: 'Notifications', exact: true })).toBeVisible();
        const notificationLink = hr.page.getByRole('link', {
          name: new RegExp(`New Job Application: ${candidateName} applied for ${postingTitle}`),
        });
        await expect(notificationLink).toBeVisible();
        await notificationLink.click();
        await expect(hr.page).toHaveURL(/\/hr\/recruitment\/applications\/[A-Za-z0-9]+$/);
        const applicationId = new URL(hr.page.url()).pathname.split('/').at(-1);
        if (!applicationId) throw new Error('The notification did not lead to a recruitment application record.');
        journey.values.applicationHashId = applicationId;
        await expect(statusBadge(hr.page, 'New')).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Move to Screening', exact: true })).toBeEnabled();

        await observer.page.goto('/dashboard');
        await expect(observer.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
        await openSidebarItem(observer, 'Human Resources', '/hr/recruitment', 'Recruitment');
        await expect(observer.page.getByRole('heading', { name: 'Recruitment', exact: true })).toBeVisible();
        await observer.page.getByRole('link', { name: 'View all' }).first().click();
        await expect(observer.page.getByRole('heading', { name: 'Applications', exact: true })).toBeVisible();
        const applicantRow = observer.page.getByRole('row').filter({ hasText: candidateName });
        await expect(applicantRow).toBeVisible();
        await applicantRow.click();
        await expect(observer.page).toHaveURL(new RegExp(`/hr/recruitment/applications/${applicationId}$`));
        await expect(statusBadge(observer.page, 'New')).toBeVisible();
      },
      verify: async () => {
        const expectedRecipients = [hrActor.email, adminActor.email];
        const notices = await applicationNotifications(contextValue<string>(journey, 'applicationHashId'));
        assertRecipientsExactly(notices.map((notice) => notice.email), expectedRecipients);
        expect(notices.every((notice) => notice.role_slug === 'hr_officer' || notice.role_slug === 'system_admin')).toBe(true);
        expect(notices.every((notice) => notice.link_to.endsWith(`/hr/recruitment/applications/${contextValue<string>(journey, 'applicationHashId')}`))).toBe(true);
        await expect.poll(async () => {
          const refreshed = await applicationNotifications(contextValue<string>(journey, 'applicationHashId'));
          return refreshed.find((notice) => notice.email === hrActor.email)?.read_at ?? null;
        }, { timeout: 10_000, message: 'following the HR notification should mark it as read' }).not.toBeNull();
        const application = await waitForApplication(candidateEmail, 'new');
        expect(application.events).toHaveLength(0);
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR notification hand-off');
        observer.diagnostics.assertClean('system administrator opens the application as observer');
      },
    });

    await runProcessStep({
      ...plannedSteps[4],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const before = await existingMailIds(candidateEmail);
        await hr.page.getByRole('button', { name: 'Move to Screening', exact: true }).click();
        await expect(hr.page.getByRole('dialog', { name: 'Advance to next stage?' })).toBeVisible();
        await hr.page.getByRole('dialog', { name: 'Advance to next stage?' }).getByRole('button', { name: 'Advance', exact: true }).click();
        await expect(statusBadge(hr.page, 'Screening')).toBeVisible();
        await assertLiveStage(observer, 'Screening', 'Move to Interview');
        await assertStageEvent(candidateEmail, 'new', 'screening', hrActor.email);
        const mail = await newCandidateMail(candidateEmail, before, `Application update: ${postingTitle}`, [postingTitle, 'Under Review', contextValue<string>(journey, 'trackingCode')]);
        journey.values.screeningMailId = mail.ID;
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'screening');
        expect(application.events.filter((event) => event.to_stage === 'screening')).toHaveLength(1);
        await expect(hr.page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await expect(observer.page.getByRole('button', { name: 'Move to Interview', exact: true })).toBeEnabled();
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR advances candidate to Screening');
        observer.diagnostics.assertClean('observer sees Screening in real time');
      },
    });

    await runProcessStep({
      ...plannedSteps[5],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const before = await existingMailIds(candidateEmail);
        await hr.page.getByRole('button', { name: 'Move to Interview', exact: true }).click();
        const dialog = hr.page.getByRole('dialog', { name: 'Schedule interview' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Date & Time').fill(interviewAt);
        await dialog.getByLabel('Interviewer').fill(interviewerName);
        await dialog.getByLabel('Location').fill(interviewLocation);
        await dialog.getByRole('button', { name: 'Schedule & Move to Interview', exact: true }).click();
        await expect(statusBadge(hr.page, 'Interview')).toBeVisible();
        await assertLiveStage(observer, 'Interview', 'Reject');
        const application = await waitForApplication(candidateEmail, 'interview');
        expect(application.interviews).toEqual([expect.objectContaining({
          interviewer_name: interviewerName,
          location: interviewLocation,
          outcome: null,
          created_by_email: hrActor.email,
        })]);
        const mail = await newCandidateMail(candidateEmail, before, `Interview scheduled: ${postingTitle}`, [candidateName, postingTitle, interviewerName, interviewLocation, 'Asia/Manila', contextValue<string>(journey, 'trackingCode')]);
        journey.values.interviewMailId = mail.ID;
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'interview');
        expect(application.interviews).toHaveLength(1);
        expect(application.interviews[0].created_by_email).toBe(hrActor.email);
        expect(application.events.some((event) => event.event_type === 'stage.advanced' && event.from_stage === 'screening' && event.to_stage === 'interview' && event.actor_email === hrActor.email)).toBe(true);
        await expect(hr.page.getByLabel(`Outcome for interview with ${interviewerName}`)).toHaveValue('pending');
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR schedules and advances to interview');
        observer.diagnostics.assertClean('observer sees Interview in real time');
      },
    });

    await runProcessStep({
      ...plannedSteps[6],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await hr.page.getByLabel(`Outcome for interview with ${interviewerName}`).selectOption('passed');
        await expect(hr.page.getByText('Interview outcome saved.', { exact: true })).toBeVisible();
        await expect(hr.page.getByLabel(`Outcome for interview with ${interviewerName}`)).toHaveValue('passed');
        await expect(statusBadge(hr.page, 'Interview')).toBeVisible();
        await expect(statusBadge(observer.page, 'Interview')).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Move to Offer', exact: true })).toBeEnabled();
        await expect(observer.page.getByRole('button', { name: 'Move to Offer', exact: true })).toBeEnabled();
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'interview');
        expect(application.interviews).toEqual([expect.objectContaining({
          interviewer_name: interviewerName,
          outcome: 'passed',
          created_by_email: hrActor.email,
        })]);
        expect(application.events.some((event) => event.event_type === 'interview.updated' && event.actor_email === hrActor.email)).toBe(true);
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR records passed interview outcome');
        observer.diagnostics.assertClean('observer sees interview outcome update');
      },
    });

    await runProcessStep({
      ...plannedSteps[7],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const before = await existingMailIds(candidateEmail);
        await hr.page.getByRole('button', { name: 'Move to Offer', exact: true }).click();
        await hr.page.getByRole('dialog', { name: 'Advance to next stage?' }).getByRole('button', { name: 'Advance', exact: true }).click();
        await expect(statusBadge(hr.page, 'Offer')).toBeVisible();
        await assertLiveStage(observer, 'Offer', 'Move to Hired');
        await assertStageEvent(candidateEmail, 'interview', 'offer', hrActor.email);
        const mail = await newCandidateMail(candidateEmail, before, `Application update: ${postingTitle}`, [postingTitle, 'Offer Extended', contextValue<string>(journey, 'trackingCode')]);
        journey.values.offerMailId = mail.ID;
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'offer');
        expect(application.events.some((event) => event.from_stage === 'interview' && event.to_stage === 'offer' && event.actor_email === hrActor.email)).toBe(true);
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR advances passed candidate to Offer');
        observer.diagnostics.assertClean('observer sees Offer in real time');
      },
    });

    await runProcessStep({
      ...plannedSteps[8],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const before = await existingMailIds(candidateEmail);
        await hr.page.getByRole('button', { name: 'Move to Hired', exact: true }).click();
        await hr.page.getByRole('dialog', { name: 'Advance to next stage?' }).getByRole('button', { name: 'Advance', exact: true }).click();
        await expect(statusBadge(hr.page, 'Hired')).toBeVisible();
        await assertLiveStage(observer, 'Hired', 'Convert to Employee');
        await expect(hr.page.getByRole('button', { name: 'Convert to Employee', exact: true })).toBeEnabled();
        await expect(hr.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        const mail = await newCandidateMail(candidateEmail, before, `Application update: ${postingTitle}`, [postingTitle, 'Hired', contextValue<string>(journey, 'trackingCode')]);
        journey.values.hiredMailId = mail.ID;
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'hired');
        expect(application.converted_employee_id).toBeNull();
        expect(application.events.some((event) => event.from_stage === 'offer' && event.to_stage === 'hired' && event.actor_email === hrActor.email)).toBe(true);
        expect(application.posting_status).toBe('filled');
        const posting = await postingSnapshot(postingTitle);
        expect(posting).toHaveLength(1);
        expect(posting[0].status).toBe('filled');
        expect(posting[0].application_count).toBe(1);
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR hires candidate and fills posting');
        observer.diagnostics.assertClean('observer sees Hired in real time');
      },
    });

    await runProcessStep({
      ...plannedSteps[9],
      page: applicantPage,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await applicantPage.getByRole('link', { name: 'the tracking page', exact: true }).click();
        await expect(applicantPage.getByRole('heading', { name: 'Track Your Application', exact: true })).toBeVisible();
        await applicantPage.getByLabel('Tracking code').fill(contextValue<string>(journey, 'trackingCode'));
        await applicantPage.getByRole('button', { name: 'Track', exact: true }).click();
        await expect(applicantPage.getByRole('heading', { name: postingTitle, exact: true })).toBeVisible();
        await expect(applicantPage.getByText('Congratulations — your application has been hired.', { exact: true })).toBeVisible();
        await expect(applicantPage.getByText('Hired', { exact: true })).toBeVisible();
      },
      verify: async () => {
        const application = await waitForApplication(candidateEmail, 'hired');
        expect(application.posting_status).toBe('filled');
        expect(application.tracking_code).toBe(contextValue<string>(journey, 'trackingCode'));
        expect(application.events.filter((event) => event.event_type === 'stage.advanced').map((event) => `${event.from_stage}->${event.to_stage}`)).toEqual([
          'new->screening',
          'screening->interview',
          'interview->offer',
          'offer->hired',
        ]);
      },
      assertInvariants: async () => applicantDiagnostics.assertClean('candidate confirms final Hired status on tracker'),
    });
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    try {
      if (primaryError) {
        await testInfo.attach('journey-context.json', {
          body: Buffer.from(JSON.stringify(journey, null, 2)),
          contentType: 'application/json',
        });
      }
    } finally {
      await Promise.allSettled([
        hr.context.close(),
        observer.context.close(),
        applicantContext.close(),
      ]);
    }
  }
});
