import { cn } from '@/lib/utils';
import { type ImgHTMLAttributes } from 'react';

/**
 * The MonyMonk mark on its own.
 *
 * Decorative by default: every place it appears, the product name is already there as
 * text or as a screen-reader-only label, and a second announcement of "MonyMonk" tells
 * a screen reader user nothing new. Pass an `alt` where the mark stands alone.
 */
export default function AppLogoIcon({ className, alt = '', ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/brand/icon.png"
            alt={alt}
            width={256}
            height={256}
            decoding="async"
            className={cn('object-contain select-none', className)}
            {...props}
        />
    );
}
