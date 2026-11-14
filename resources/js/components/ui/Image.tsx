import type { CSSProperties, ImgHTMLAttributes } from 'react';

export interface ImageProps extends Omit<ImgHTMLAttributes<HTMLImageElement>, 'src' | 'width' | 'height'> {
    src: string;
    alt: string;
    width?: number | string;
    height?: number | string;
    /** Fill the (relatively positioned) parent, like next/image `fill`. */
    fill?: boolean;
    /** Load eagerly with high priority (above-the-fold images). */
    priority?: boolean;
    /** Accepted for compatibility with next/image; ignored. */
    quality?: number;
    placeholder?: string;
    blurDataURL?: string;
    unoptimized?: boolean;
}

/** Drop-in replacement for next/image that renders a plain <img>. */
export default function Image({
    fill,
    priority,
    quality: _quality,
    placeholder: _placeholder,
    blurDataURL: _blur,
    unoptimized: _unoptimized,
    loading,
    style,
    ...props
}: ImageProps) {
    const fillStyle: CSSProperties | undefined = fill
        ? { position: 'absolute', inset: 0, width: '100%', height: '100%', ...style }
        : style;

    return (
        <img
            {...props}
            style={fillStyle}
            loading={priority ? 'eager' : (loading ?? 'lazy')}
            decoding="async"
            {...(priority ? { fetchpriority: 'high' } : {})}
        />
    );
}
