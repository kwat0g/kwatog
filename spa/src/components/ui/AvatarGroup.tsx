import { Avatar } from './Avatar';

export interface AvatarPerson {
  id: string;
  name: string;
  src?: string | null;
}

export function AvatarGroup({
  people,
  label,
  max = 4,
}: {
  people: AvatarPerson[];
  label: string;
  max?: number;
}) {
  const visible = people.slice(0, Math.max(0, max));
  const remaining = people.length - visible.length;
  return (
    <div role="group" aria-label={label} className="inline-flex items-center -space-x-2">
      {visible.map((person) => (
        <Avatar
          key={person.id}
          identityKey={person.id}
          name={person.name}
          src={person.src}
          size="sm"
          className="ring-2 ring-canvas"
        />
      ))}
      {remaining > 0 && (
        <span
          role="img"
          aria-label={`${remaining} more ${remaining === 1 ? 'person' : 'people'}`}
          className="inline-flex h-6 min-w-6 items-center justify-center rounded-full border border-default bg-elevated px-1 text-caption text-secondary ring-2 ring-canvas"
        >
          +{remaining}
        </span>
      )}
    </div>
  );
}
