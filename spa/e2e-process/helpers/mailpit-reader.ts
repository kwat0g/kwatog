const MAILPIT_API = 'http://mailpit:8025/api/v1';

export type MailpitSummary = {
  ID: string;
  From: { Address: string };
  To: Array<{ Address: string }>;
  Subject: string;
  Created: string;
  Snippet?: string;
};

export async function listMailpitMessages(limit = 100): Promise<MailpitSummary[]> {
  const response = await fetch(`${MAILPIT_API}/messages?start=0&limit=${limit}`);
  if (!response.ok) throw new Error(`Mailpit list returned ${response.status}.`);
  const result = await response.json() as { messages?: MailpitSummary[] };
  return result.messages ?? [];
}

export async function getMailpitMessage(id: string): Promise<Record<string, unknown>> {
  const response = await fetch(`${MAILPIT_API}/message/${encodeURIComponent(id)}`);
  if (!response.ok) throw new Error(`Mailpit message lookup returned ${response.status}.`);
  return response.json() as Promise<Record<string, unknown>>;
}

export async function getMailpitMessageRaw(id: string): Promise<string> {
  const response = await fetch(`${MAILPIT_API}/message/${encodeURIComponent(id)}/raw`);
  if (!response.ok) throw new Error(`Mailpit raw-message lookup returned ${response.status}.`);
  return response.text();
}

export async function findMailpitMessagesFor(recipient: string): Promise<MailpitSummary[]> {
  const messages = await listMailpitMessages();
  const normalizedRecipient = recipient.toLowerCase();
  return messages.filter((message) => message.To.some((target) => target.Address.toLowerCase() === normalizedRecipient));
}
