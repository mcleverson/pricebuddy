import {zColor} from '@remotion/zod-types';
import {z} from 'zod';
import defaults from './defaults.json';

export const FPS = 30;
export const TRANSITION_FRAMES = 15;

export type Template = {
    primaryColor: string;
    backgroundColor: string;
    textColor: string;
    cta: string;
    secondsPerSlide: number;
    maxVideoSeconds: number;
    transition: 'fade' | 'slide' | 'none';
    showOriginalPrice: boolean;
    showDiscount: boolean;
    showStore: boolean;
};

/**
 * Visual defaults of the carousel, in defaults.json so the HTTP API can serve them
 * to the caller's form. Edit them there (or live in Remotion Studio); callers only
 * send the options they want to override.
 */
export const defaultTemplate = defaults as Template;

export const carouselSchema = z.object({
    product: z.object({
        title: z.string(),
        price: z.number().nullable(),
        originalPrice: z.number().nullable().optional(),
        discountPercent: z.number().nullable().optional(),
        store: z.string().nullable().optional(),
    }),
    // `file` is a URL or a file of the public dir; `durationInSeconds` is only read for videos.
    media: z.array(z.object({
        file: z.string(),
        type: z.enum(['image', 'video']),
        durationInSeconds: z.number().nullable().optional(),
    })),
    template: z.object({
        primaryColor: zColor(),
        backgroundColor: zColor(),
        textColor: zColor(),
        cta: z.string(),
        secondsPerSlide: z.number().min(1).max(15),
        maxVideoSeconds: z.number().min(1).max(60),
        transition: z.enum(['fade', 'slide', 'none']),
        showOriginalPrice: z.boolean(),
        showDiscount: z.boolean(),
        showStore: z.boolean(),
    }).partial(),
});

export type CarouselProps = z.infer<typeof carouselSchema>;

export const withDefaults = (template: CarouselProps['template']): Template => ({...defaultTemplate, ...template});

/** Frames of each slide: images last `secondsPerSlide`, videos their own length up to `maxVideoSeconds`. */
export const slideFrames = ({media, template}: CarouselProps): number[] => {
    const t = withDefaults(template);
    const seconds = media.length === 0
        ? [t.secondsPerSlide]
        : media.map((m) => m.type === 'video' && m.durationInSeconds
            ? Math.min(m.durationInSeconds, t.maxVideoSeconds)
            : t.secondsPerSlide);

    return seconds.map((s) => Math.max(Math.round(s * FPS), TRANSITION_FRAMES + 1));
};

export const sampleProps: CarouselProps = {
    product: {
        title: 'Fone de Ouvido Bluetooth com Cancelamento de Ruído',
        price: 199.9,
        originalPrice: 349.9,
        discountPercent: 43,
        store: 'Amazon',
    },
    media: [],
    template: {},
};
