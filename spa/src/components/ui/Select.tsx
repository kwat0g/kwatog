import {
  Children,
  forwardRef,
  isValidElement,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type ReactNode,
  type SelectHTMLAttributes,
} from 'react';
import { LuChevronDown } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { SearchableSelect } from './SearchableSelect';

/**
 * Named `fieldSize` because the native `size` attribute on <select> is a row
 * count. `sm` is for controls inside table rows, `lg` for the touch targets on
 * the factory kiosk and mobile pages, `xl` for the count entry on those same
 * pages — read at arm's length, tapped with gloves on.
 */
export type FieldSize = 'sm' | 'md' | 'lg' | 'xl';

const shellSize: Record<FieldSize, string> = {
  sm: 'h-7',
  md: 'h-8',
  lg: 'h-11',
  xl: 'h-14',
};

const textSize: Record<FieldSize, string> = {
  sm: 'pl-2 pr-7 text-xs',
  md: 'pl-3 pr-8 text-md',
  lg: 'pl-3 pr-8 text-md',
  xl: 'pl-4 pr-9 text-xl',
};

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  searchable?: boolean;
  onSearch?: (search: string) => void;
  label?: string;
  helper?: string;
  error?: string;
  containerClassName?: string;
  fieldSize?: FieldSize;
  loading?: boolean;
}

function optionText(node: ReactNode): string {
  return Children.toArray(node)
    .map((child) =>
      isValidElement<{ children?: ReactNode }>(child)
        ? optionText(child.props.children)
        : String(child),
    )
    .join('');
}

const SearchableNativeSelect = forwardRef<HTMLSelectElement, SelectProps>(
  (
    {
      children,
      label,
      helper,
      error,
      containerClassName,
      fieldSize = 'md',
      onSearch,
      loading,
      searchable: _,
      ...props
    },
    ref,
  ) => {
    const native = useRef<HTMLSelectElement | null>(null);
    const visible = useRef<HTMLInputElement>(null);
    const [selected, setSelected] = useState('');
    const selectedOption = useRef<{ value: string; label: string; disabled: boolean }>();
    const collect = (
      nodes: ReactNode,
      groupDisabled = false,
    ): Array<{ value: string; label: string; disabled: boolean }> =>
      Children.toArray(nodes).flatMap((child) => {
        if (
          !isValidElement<{ value?: string | number; disabled?: boolean; children?: ReactNode }>(
            child,
          )
        )
          return [];
        if (child.type !== 'option')
          return collect(child.props.children, groupDisabled || !!child.props.disabled);
        return [
          {
            value: String(child.props.value ?? optionText(child.props.children)),
            label: optionText(child.props.children).trim(),
            disabled: groupDisabled || !!child.props.disabled,
          },
        ];
      });
    const options = collect(children);
    useLayoutEffect(() => {
      if (
        selected &&
        !options.some((option) => option.value === selected) &&
        !onSearch &&
        (props.value === undefined || props.value === selected) &&
        native.current
      ) {
        native.current.value = '';
        native.current.dispatchEvent(new Event('change', { bubbles: true }));
      }
      const nativeValue = native.current?.value ?? '';
      if (nativeValue !== selected) setSelected(nativeValue);
    }, [selected, options, onSearch, props.value]);
    const current = options.find((option) => option.value === selected);
    if (current) selectedOption.current = current;
    const retained =
      onSearch && selected && !current && selectedOption.current?.value === selected
        ? selectedOption.current
        : undefined;
    if (retained) options.push(retained);
    return (
      <>
        <select
          {...props}
          aria-hidden="true"
          tabIndex={-1}
          className="sr-only"
          ref={(element) => {
            native.current = element;
            if (typeof ref === 'function') ref(element);
            else if (ref) ref.current = element;
          }}
          id={undefined}
          onInvalid={(event) => {
            event.preventDefault();
            visible.current?.focus();
          }}
        >
          {children}
          {retained && (
            <option value={retained.value} disabled={retained.disabled}>
              {retained.label}
            </option>
          )}
        </select>
        <SearchableSelect
          ref={visible}
          label={label}
          aria-label={props['aria-label']}
          id={props.id}
          required={props.required}
          loading={loading}
          helper={helper}
          error={error}
          containerClassName={containerClassName}
          fieldSize={fieldSize}
          className={props.className}
          title={props.title}
          disabled={props.disabled}
          options={options}
          value={selected}
          onSearch={onSearch}
          onBlur={() =>
            native.current?.dispatchEvent(new FocusEvent('focusout', { bubbles: true }))
          }
          onChange={(value) => {
            if (!native.current || native.current.matches(':disabled')) return;
            native.current.value = value;
            native.current.dispatchEvent(new Event('change', { bubbles: true }));
            setSelected(native.current.value);
          }}
        />
      </>
    );
  },
);

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  (
    {
      label,
      helper,
      error,
      required,
      id,
      className,
      containerClassName,
      fieldSize = 'md',
      searchable,
      onSearch,
      loading,
      children,
      ...rest
    },
    ref,
  ) => {
    const generatedId = useId().replaceAll(':', '');
    const selectId = id ?? `select-${generatedId}`;
    if (searchable)
      return (
        <SearchableNativeSelect
          {...rest}
          ref={ref}
          id={selectId}
          label={label}
          helper={helper}
          error={error}
          required={required}
          className={className}
          containerClassName={containerClassName}
          fieldSize={fieldSize}
          onSearch={onSearch}
          loading={loading}
        >
          {children}
        </SearchableNativeSelect>
      );
    return (
      <div className={cn('flex flex-col gap-1', containerClassName)}>
        {label && (
          <label htmlFor={selectId} className="text-label text-muted font-medium">
            {label}
            {required && (
              <span aria-hidden="true" className="text-danger-fg ml-0.5">
                *
              </span>
            )}
          </label>
        )}
        <div
          data-touch-target
          className={cn(
            'relative flex items-stretch rounded-md border bg-elevated overflow-hidden transition-colors duration-fast',
            'hover:border-strong hover:bg-canvas',
            'focus-within:ring-[3px] focus-within:ring-accent/20 focus-within:border-accent focus-within:bg-canvas',
            shellSize[fieldSize],
            error ? 'border-danger' : 'border-default',
          )}
        >
          <select
            ref={ref}
            id={selectId}
            required={required}
            aria-invalid={!!error}
            aria-describedby={
              error ? `${selectId}-error` : helper ? `${selectId}-helper` : undefined
            }
            className={cn(
              'flex-1 min-w-0 bg-transparent appearance-none outline-none cursor-pointer',
              'disabled:cursor-not-allowed disabled:opacity-60',
              textSize[fieldSize],
              className,
            )}
            {...rest}
          >
            {children}
          </select>
          <span
            aria-hidden
            className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-muted"
          >
            <LuChevronDown size={fieldSize === 'sm' ? 12 : 14} />
          </span>
        </div>
        {error && (
          <span id={`${selectId}-error`} role="alert" className="text-caption text-danger-fg">
            {error}
          </span>
        )}
        {helper && !error ? (
          <span id={`${selectId}-helper`} className="text-caption text-muted">
            {helper}
          </span>
        ) : null}
      </div>
    );
  },
);
Select.displayName = 'Select';
