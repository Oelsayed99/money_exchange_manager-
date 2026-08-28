import { cn } from '@/lib/utils';

/**
 * The MonyMonk wordmark, in whichever ink the current theme needs.
 *
 * Two files rather than one recoloured file: the lettering is near-black on light and
 * white on dark, and no CSS filter turns one into the other without dragging the green
 * chevrons somewhere else in the colour wheel. The swap is CSS rather than a React hook
 * because the theme class is set by a blocking script in the head — so the right one is
 * chosen before the first paint instead of flipping after it.
 *
 * Give it a height; the width follows.
 */
export default function AppWordmark({ className }: { className?: string }) {
    return (
        <>
            <img
                src="/brand/wordmark-light.png"
                alt="MonyMonk"
                width={487}
                height={96}
                decoding="async"
                className={cn('w-auto select-none dark:hidden', className)}
            />
            <img
                src="/brand/wordmark-dark.png"
                alt="MonyMonk"
                width={483}
                height={96}
                decoding="async"
                className={cn('hidden w-auto select-none dark:block', className)}
            />
        </>
    );
}
