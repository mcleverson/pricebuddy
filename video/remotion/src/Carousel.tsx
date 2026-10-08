import {fade} from '@remotion/transitions/fade';
import {linearTiming, TransitionSeries} from '@remotion/transitions';
import {slide} from '@remotion/transitions/slide';
import React from 'react';
import {
    AbsoluteFill,
    Img,
    interpolate,
    OffthreadVideo,
    spring,
    staticFile,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import {CarouselProps, slideFrames, Template, TRANSITION_FRAMES, withDefaults} from './schema';

type Media = CarouselProps['media'][number];

const FONT = '"Noto Sans", "Noto Color Emoji", sans-serif';

const src = (file: string) => (/^https?:\/\//.test(file) ? file : staticFile(file));

const brl = (value: number) => value.toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});

/** An image fits whole over a blurred copy of itself (product photos are rarely 9:16), with a slow zoom. */
const ImageSlide: React.FC<{file: string}> = ({file}) => {
    const frame = useCurrentFrame();
    const {durationInFrames} = useVideoConfig();
    const scale = interpolate(frame, [0, durationInFrames], [1, 1.08]);

    return (
        <AbsoluteFill>
            <Img src={src(file)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: 'blur(40px) brightness(0.6)', transform: 'scale(1.2)'}} />
            <AbsoluteFill style={{justifyContent: 'center', alignItems: 'center', paddingBottom: 420}}>
                <Img src={src(file)} style={{width: '90%', height: '70%', objectFit: 'contain', transform: `scale(${scale})`}} />
            </AbsoluteFill>
        </AbsoluteFill>
    );
};

const Slide: React.FC<{media: Media | null; template: Template}> = ({media, template}) => {
    if (media === null) {
        return <AbsoluteFill style={{backgroundColor: template.backgroundColor}} />;
    }

    return media.type === 'video'
        ? <OffthreadVideo src={src(media.file)} style={{width: '100%', height: '100%', objectFit: 'cover'}} />
        : <ImageSlide file={media.file} />;
};

const Overlay: React.FC<{product: CarouselProps['product']; template: Template}> = ({product, template: t}) => {
    const frame = useCurrentFrame();
    const {fps} = useVideoConfig();
    const enter = spring({frame, fps, config: {damping: 200}});
    const hasOriginal = t.showOriginalPrice && product.originalPrice && product.price && product.originalPrice > product.price;
    const discount = product.discountPercent ?? (hasOriginal ? (1 - product.price! / product.originalPrice!) * 100 : null);
    const badge = {borderRadius: 999, padding: '14px 32px', fontSize: 44, fontWeight: 800} as const;

    return (
        <AbsoluteFill style={{fontFamily: FONT, color: t.textColor, justifyContent: 'space-between', padding: 64}}>
            <div style={{display: 'flex', justifyContent: 'space-between', opacity: enter}}>
                <div>{t.showStore && product.store ? <span style={{...badge, background: 'rgba(0,0,0,0.55)'}}>{product.store}</span> : null}</div>
                <div>{t.showDiscount && discount ? <span style={{...badge, background: t.primaryColor}}>-{Math.round(discount)}%</span> : null}</div>
            </div>
            <div style={{
                transform: `translateY(${interpolate(enter, [0, 1], [400, 0])}px)`,
                background: 'linear-gradient(180deg, rgba(0,0,0,0.55), rgba(0,0,0,0.85))',
                borderRadius: 40,
                padding: 48,
                display: 'flex',
                flexDirection: 'column',
                gap: 20,
            }}>
                <div style={{fontSize: 52, fontWeight: 700, lineHeight: 1.2, display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden'}}>
                    {product.title}
                </div>
                {hasOriginal ? <div style={{fontSize: 44, opacity: 0.8, textDecoration: 'line-through'}}>De {brl(product.originalPrice!)}</div> : null}
                {product.price ? <div style={{fontSize: 96, fontWeight: 900, color: t.primaryColor}}>{hasOriginal ? 'Por ' : ''}{brl(product.price)}</div> : null}
                {t.cta ? <div style={{...badge, alignSelf: 'flex-start', background: t.primaryColor}}>{t.cta}</div> : null}
            </div>
        </AbsoluteFill>
    );
};

export const Carousel: React.FC<CarouselProps> = (props) => {
    const t = withDefaults(props.template);
    const frames = slideFrames(props);
    const slides: (Media | null)[] = props.media.length ? props.media : [null];
    const presentation = t.transition === 'slide' ? slide({direction: 'from-right'}) : fade();

    return (
        <AbsoluteFill style={{backgroundColor: t.backgroundColor}}>
            <TransitionSeries>
                {slides.map((media, i) => (
                    <React.Fragment key={i}>
                        {i > 0 && t.transition !== 'none'
                            ? <TransitionSeries.Transition presentation={presentation} timing={linearTiming({durationInFrames: TRANSITION_FRAMES})} />
                            : null}
                        <TransitionSeries.Sequence durationInFrames={frames[i]}>
                            <Slide media={media} template={t} />
                        </TransitionSeries.Sequence>
                    </React.Fragment>
                ))}
            </TransitionSeries>
            <Overlay product={props.product} template={t} />
        </AbsoluteFill>
    );
};
