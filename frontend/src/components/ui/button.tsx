import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** shadcn/ui button, coloured with Campus TAB tokens (works in both themes). */
const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-input text-sm font-medium transition-colors duration-(--ct-motion) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        primary: 'bg-primary-solid text-on-solid hover:bg-primary-solid-hover',
        outline: 'border border-border bg-surface text-fg hover:bg-surface-muted',
        ghost: 'text-fg hover:bg-surface-muted',
        danger: 'bg-danger-solid text-on-solid hover:opacity-90',
      },
      size: {
        md: 'h-11 px-4',
        sm: 'h-9 px-3',
        icon: 'size-11',
      },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
);

export interface ButtonProps extends ComponentProps<'button'>, VariantProps<typeof buttonVariants> {
  asChild?: boolean;
}

export function Button({ className, variant, size, asChild = false, ...props }: ButtonProps) {
  const Component = asChild ? Slot.Root : 'button';

  return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

export { buttonVariants };
