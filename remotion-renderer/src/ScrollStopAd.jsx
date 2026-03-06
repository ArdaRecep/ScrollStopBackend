import React, {useMemo} from 'react';
import {
  AbsoluteFill,
  Audio,
  Img,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';

const findActiveScene = (scenes, frame, fps) => {
  let cursor = 0;

  for (let i = 0; i < scenes.length; i += 1) {
    const sceneFrames = Math.max(1, Math.round((Number(scenes[i].durationSeconds) || 1) * fps));
    if (frame < cursor + sceneFrames) {
      return {scene: scenes[i], sceneIndex: i, localFrame: frame - cursor, sceneFrames};
    }
    cursor += sceneFrames;
  }

  const fallback = scenes[Math.max(0, scenes.length - 1)] ?? null;
  return {
    scene: fallback,
    sceneIndex: Math.max(0, scenes.length - 1),
    localFrame: 0,
    sceneFrames: Math.max(1, Math.round((Number(fallback?.durationSeconds) || 1) * fps)),
  };
};

export const ScrollStopAd = ({scenes = [], voiceoverAudioSrc = null, tone = 'Bold'}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();

  const active = useMemo(() => findActiveScene(scenes, frame, fps), [scenes, frame, fps]);
  const scene = active.scene;

  if (!scene) {
    return <AbsoluteFill style={{backgroundColor: 'black'}} />;
  }

  const fadeIn = interpolate(active.localFrame, [0, 12], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const textLift = spring({
    frame: active.localFrame,
    fps,
    from: 20,
    to: 0,
    config: {
      damping: 14,
      mass: 0.8,
      stiffness: 90,
    },
  });

  const textScale = spring({
    frame: active.localFrame,
    fps,
    from: 0.96,
    to: 1,
    config: {
      damping: 12,
      mass: 0.7,
      stiffness: 95,
    },
  });

  const toneColor =
    String(tone).toLowerCase() === 'luxury'
      ? '#e7c66e'
      : String(tone).toLowerCase() === 'urgent'
        ? '#ff6b6b'
        : '#ffffff';

  return (
    <AbsoluteFill style={{backgroundColor: '#07090f'}}>
      <AbsoluteFill style={{opacity: fadeIn}}>
        {scene.imageSrc ? (
          <Img
            src={scene.imageSrc}
            style={{
              width: '100%',
              height: '100%',
              objectFit: 'cover',
            }}
          />
        ) : null}
      </AbsoluteFill>

      <AbsoluteFill
        style={{
          background:
            'linear-gradient(180deg, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.62) 72%, rgba(0,0,0,0.82) 100%)',
        }}
      />

      <AbsoluteFill
        style={{
          justifyContent: 'flex-end',
          padding: 72,
        }}
      >
        <div
          style={{
            color: toneColor,
            fontFamily: 'Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif',
            fontSize: 76,
            fontWeight: 800,
            lineHeight: 1.05,
            letterSpacing: -1.2,
            transform: `translateY(${textLift}px) scale(${textScale})`,
            textShadow: '0 6px 18px rgba(0,0,0,0.5)',
            whiteSpace: 'pre-wrap',
          }}
        >
          {scene.overlayText}
        </div>
      </AbsoluteFill>

      {voiceoverAudioSrc ? <Audio src={voiceoverAudioSrc} /> : null}
    </AbsoluteFill>
  );
};
