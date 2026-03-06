import React from 'react';
import {Composition} from 'remotion';
import {ScrollStopAd} from './ScrollStopAd.jsx';

const aspectToDimensions = (aspectRatio) => {
  if (aspectRatio === '9:16') {
    return {width: 1080, height: 1920};
  }

  return {width: 1080, height: 1920};
};

export const RemotionRoot = () => {
  return (
    <Composition
      id="ScrollStopAd"
      component={ScrollStopAd}
      defaultProps={{
        durationSeconds: 15,
        fps: 30,
        aspectRatio: '9:16',
        scenes: [],
        voiceoverAudioSrc: null,
        tone: 'Bold',
      }}
      durationInFrames={450}
      fps={30}
      width={1080}
      height={1920}
      calculateMetadata={({props}) => {
        const fps = Math.max(24, Math.min(60, Number(props?.fps ?? 30)));
        const durationSeconds = Math.max(1, Number(props?.durationSeconds ?? 15));
        const durationInFrames = Math.max(1, Math.round(durationSeconds * fps));
        const {width, height} = aspectToDimensions(String(props?.aspectRatio ?? '9:16'));

        return {
          durationInFrames,
          fps,
          width,
          height,
        };
      }}
    />
  );
};
