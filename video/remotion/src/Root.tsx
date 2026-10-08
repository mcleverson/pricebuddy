import {Composition} from 'remotion';
import {Carousel} from './Carousel';
import {carouselSchema, FPS, sampleProps, slideFrames, TRANSITION_FRAMES, withDefaults} from './schema';

export const RemotionRoot: React.FC = () => (
    <Composition
        id="Carousel"
        component={Carousel}
        schema={carouselSchema}
        defaultProps={sampleProps}
        width={1080}
        height={1920}
        fps={FPS}
        durationInFrames={FPS * 3}
        calculateMetadata={({props}) => {
            const frames = slideFrames(props);
            const overlap = withDefaults(props.template).transition === 'none' ? 0 : TRANSITION_FRAMES;

            return {durationInFrames: frames.reduce((a, b) => a + b, 0) - overlap * (frames.length - 1)};
        }}
    />
);
