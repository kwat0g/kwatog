import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { ChevronRight, ChevronDown, Plus, Pencil, Trash2, Building2 } from 'lucide-react';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';
import type { Department } from '@/types/hr';
import { cn } from '@/lib/cn';

const schema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(100)
    .regex(/^[\p{L}0-9\s.&\-,()]+$/u, 'Letters, digits, spaces, and . & - , ( )'),
  code: z.string().trim().min(2, 'At least 2 characters').max(20)
    .regex(/^[A-Z0-9_-]+$/, 'Uppercase letters, digits, _ or -').transform((s) => s.toUpperCase()),
  parent_id: z.string().optional(),
  is_active: z.boolean(),
});
type FormValues = z.infer<typeof schema>;

interface TreeNode extends Department {
  children: TreeNode[];
}

function buildTree(rows: Department[]): TreeNode[] {
  const map = new Map<string, TreeNode>();
  rows.forEach((r) => map.set(r.id, { ...r, children: [] }));
  const roots: TreeNode[] = [];
  rows.forEach((r) => {
    const node = map.get(r.id)!;
    if (r.parent_id && map.has(r.parent_id)) {
      map.get(r.parent_id)!.children.push(node);
    } else {
      roots.push(node);
    }
  });
  const sortTree = (nodes: TreeNode[]) => {
    nodes.sort((a, b) => a.name.localeCompare(b.name));
    nodes.forEach((n) => sortTree(n.children));
  };
  sortTree(roots);
  return roots;
}

export default function DepartmentsPage() {
  const { can } = usePermission();
  const qc = useQueryClient();

  const { data: rows = [], isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
  });

  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Department | null>(null);

  const tree = useMemo(() => buildTree(rows), [rows]);
  const selected = useMemo(() => rows.find((r) => r.id === selectedId), [rows, selectedId]);
  const editing = useMemo(() => rows.find((r) => r.id === editingId), [rows, editingId]);

  const toggle = (id: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const openCreate = () => {
    setEditingId(null);
    setModalOpen(true);
  };
  const openEdit = (id: string) => {
    setEditingId(id);
    setModalOpen(true);
  };
  const closeModal = () => {
    setModalOpen(false);
    setEditingId(null);
  };

  const deleteMutation = useMutation({
    mutationFn: (id: string) => departmentsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hr', 'departments'] });
      toast.success('Department deleted.');
      setPendingDelete(null);
      setSelectedId(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete department.');
    },
  });

  return (
    <div>
      <PageHeader
        title="Departments"
        subtitle={`${rows.length} departments`}
        actions={
          can('hr.departments.manage') && (
            <Button variant="primary" size="sm" onClick={openCreate} icon={<Plus size={14} />}>
              Add department
            </Button>
          )
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        {/* Tree */}
        <Panel title="Organisation tree" noPadding>
          {isLoading && (
            <div className="p-4 space-y-2">
              {Array.from({ length: 6 }).map((_, i) => <SkeletonBlock key={i} className="h-7" />)}
            </div>
          )}
          {isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load departments"
              description="Something went wrong. Try again."
              action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
            />
          )}
          {!isLoading && !isError && tree.length === 0 && (
            <EmptyState
              icon="inbox"
              title="No departments yet"
              description="Departments organise employees by function. Add your first one to get started."
              action={can('hr.departments.manage') ? <Button variant="primary" onClick={openCreate}>Add department</Button> : null}
            />
          )}
          {!isLoading && !isError && tree.length > 0 && (
            <ul className="py-2 text-sm">
              {tree.map((node) => (
                <TreeRow
                  key={node.id}
                  node={node}
                  depth={0}
                  expanded={expanded}
                  selectedId={selectedId}
                  onToggle={toggle}
                  onSelect={setSelectedId}
                />
              ))}
            </ul>
          )}
        </Panel>

        {/* Detail panel */}
        <Panel title="Details">
          {!selected && <p className="text-sm text-muted">Select a department to view its details.</p>}
          {selected && (
            <div className="space-y-3 text-sm">
              <div>
                <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Name</div>
                <div className="font-medium">{selected.name}</div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Code</div>
                <div className="font-mono">{selected.code}</div>
              </div>
              <div className="flex gap-4">
                <div>
                  <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Positions</div>
                  <div className="font-mono tabular-nums">{selected.positions_count ?? 0}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Employees</div>
                  <div className="font-mono tabular-nums">{selected.employees_count ?? 0}</div>
                </div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Status</div>
                <Chip variant={selected.is_active ? 'success' : 'neutral'}>
                  {selected.is_active ? 'Active' : 'Inactive'}
                </Chip>
              </div>
              {selected.head_employee && (
                <div>
                  <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Head</div>
                  <div>{selected.head_employee.full_name}</div>
                </div>
              )}
              {can('hr.departments.manage') && (
                <div className="flex gap-2 pt-3 border-t border-default">
                  <Button variant="secondary" size="sm" onClick={() => openEdit(selected.id)} icon={<Pencil size={12} />}>
                    Edit
                  </Button>
                  <Button variant="danger" size="sm" onClick={() => setPendingDelete(selected)} icon={<Trash2 size={12} />}>
                    Delete
                  </Button>
                </div>
              )}
            </div>
          )}
        </Panel>
      </div>

      {modalOpen && (
        <DepartmentFormModal
          rows={rows}
          editing={editing ?? null}
          onClose={closeModal}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['hr', 'departments'] });
            closeModal();
          }}
        />
      )}

      {pendingDelete && (
        <Modal isOpen onClose={() => setPendingDelete(null)} size="sm" title="Delete department">
          <p className="text-sm py-2">
            Delete <span className="font-medium">{pendingDelete.name}</span>? This cannot be undone.
          </p>
          <div className="flex justify-end gap-2 pt-3 border-t border-default">
            <Button variant="secondary" onClick={() => setPendingDelete(null)} disabled={deleteMutation.isPending}>Cancel</Button>
            <Button
              variant="danger"
              onClick={() => deleteMutation.mutate(pendingDelete.id)}
              disabled={deleteMutation.isPending}
              loading={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function TreeRow({
  node, depth, expanded, selectedId, onToggle, onSelect,
}: {
  node: TreeNode;
  depth: number;
  expanded: Set<string>;
  selectedId: string | null;
  onToggle: (id: string) => void;
  onSelect: (id: string) => void;
}) {
  const isOpen = expanded.has(node.id);
  const hasKids = node.children.length > 0;
  return (
    <>
      <li
        className={cn(
          'flex items-center gap-1.5 h-8 px-3 hover:bg-elevated cursor-pointer',
          selectedId === node.id && 'bg-elevated',
        )}
        style={{ paddingLeft: 12 + depth * 16 }}
        onClick={() => onSelect(node.id)}
      >
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); if (hasKids) onToggle(node.id); }}
          className="w-4 h-4 flex items-center justify-center text-muted shrink-0"
          aria-label={hasKids ? (isOpen ? 'Collapse' : 'Expand') : ''}
        >
          {hasKids ? (isOpen ? <ChevronDown size={12} /> : <ChevronRight size={12} />) : <span className="w-2 h-2 rounded-full bg-elevated" />}
        </button>
        <Building2 size={13} className="text-muted shrink-0" />
        <span className="font-medium truncate">{node.name}</span>
        <span className="font-mono text-xs text-muted ml-1.5">{node.code}</span>
        <span className="ml-auto flex items-center gap-2 text-xs text-muted">
          <span className="font-mono tabular-nums">{node.employees_count ?? 0}</span>
          {!node.is_active && <Chip variant="neutral">Inactive</Chip>}
        </span>
      </li>
      {hasKids && isOpen && node.children.map((child) => (
        <TreeRow key={child.id} node={child} depth={depth + 1}
          expanded={expanded} selectedId={selectedId} onToggle={onToggle} onSelect={onSelect} />
      ))}
    </>
  );
}

function DepartmentFormModal({
  rows, editing, onClose, onSaved,
}: {
  rows: Department[];
  editing: Department | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = !!editing;
  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: editing?.name ?? '',
      code: editing?.code ?? '',
      parent_id: editing?.parent_id ?? '',
      is_active: editing?.is_active ?? true,
    },
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => {
      const payload = { ...data, parent_id: data.parent_id || null };
      return isEdit
        ? departmentsApi.update(editing!.id, payload)
        : departmentsApi.create(payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Department updated.' : 'Department created.');
      onSaved();
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) =>
          setError(field as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to save department.');
      }
    },
  });

  const parents = rows.filter((r) => r.id !== editing?.id);

  return (
    <Modal isOpen onClose={onClose} title={isEdit ? 'Edit department' : 'Add department'} size="md">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-3 py-2">
        <Input label="Name" {...register('name')} error={errors.name?.message} required />
        <Input label="Code" {...register('code')} error={errors.code?.message} placeholder="EXEC" required />
        <Select
          label="Parent department"
          {...register('parent_id')}
          error={errors.parent_id?.message}
        >
          <option value="">— None (root) —</option>
          {parents.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        <div className="pt-1">
          <Switch label="Active" {...register('is_active')} />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting || mutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create department'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
