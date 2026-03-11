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

const pickFontSize = (textLength) => {
  if (textLength > 38) return 56;
  if (textLength > 28) return 62;
  return 68;
};

const THEME_VARIANTS = [
  {
    cardBackground:
      'linear-gradient(145deg, rgba(12,18,34,0.78) 0%, rgba(12,18,34,0.5) 100%)',
    cardBorder: '1px solid rgba(196,214,255,0.35)',
    accent: 'linear-gradient(90deg, #84b2ff 0%, #d8e5ff 100%)',
    textColor: '#f5f9ff',
    fontFamily:
      '"SF Pro Display", "Avenir Next", "Inter", system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif',
  },
  {
    cardBackground:
      'linear-gradient(145deg, rgba(33,22,14,0.8) 0%, rgba(33,22,14,0.56) 100%)',
    cardBorder: '1px solid rgba(255,214,168,0.35)',
    accent: 'linear-gradient(90deg, #ffd07f 0%, #ff9f79 100%)',
    textColor: '#fff7eb',
    fontFamily:
      '"Avenir Next Condensed", "DIN Condensed", "Arial Narrow", "Trebuchet MS", sans-serif',
  },
  {
    cardBackground:
      'linear-gradient(145deg, rgba(19,34,23,0.82) 0%, rgba(19,34,23,0.56) 100%)',
    cardBorder: '1px solid rgba(165,235,198,0.34)',
    accent: 'linear-gradient(90deg, #7bf7c2 0%, #5ec8ff 100%)',
    textColor: '#ecfff4',
    fontFamily:
      '"Gill Sans", "Trebuchet MS", "Avenir Next", "Inter", system-ui, sans-serif',
  },
  {
    cardBackground:
      'linear-gradient(145deg, rgba(37,15,36,0.82) 0%, rgba(37,15,36,0.54) 100%)',
    cardBorder: '1px solid rgba(246,180,255,0.33)',
    accent: 'linear-gradient(90deg, #c6a6ff 0%, #ff9fda 100%)',
    textColor: '#fff3ff',
    fontFamily:
      '"Helvetica Neue", "Avenir Next", "Optima", "Inter", system-ui, sans-serif',
  },
];

const hashSeed = (value) => {
  const source = String(value ?? '');
  let hash = 0;
  for (let i = 0; i < source.length; i += 1) {
    hash = (hash << 5) - hash + source.charCodeAt(i);
    hash |= 0;
  }

  return Math.abs(hash);
};

const pickTheme = (seed) => {
  if (THEME_VARIANTS.length === 0) {
    return THEME_VARIANTS[0];
  }

  const index = hashSeed(seed) % THEME_VARIANTS.length;
  return THEME_VARIANTS[index];
};

export const ScrollStopAd = ({scenes = [], voiceoverAudioSrc = null, tone = 'Bold'}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();

  const active = useMemo(() => findActiveScene(scenes, frame, fps), [scenes, frame, fps]);
  const scene = active.scene;
  const themeSeed = useMemo(
    () =>
      `${tone}|${scenes
        .map((item) => String(item?.overlayText ?? ''))
        .join('|')}`,
    [tone, scenes],
  );
  const theme = useMemo(() => pickTheme(themeSeed), [themeSeed]);

  if (!scene) {
    return <AbsoluteFill style={{backgroundColor: 'black'}} />;
  }

  const overlayText = String(scene.overlayText ?? '').trim();
  const captionLength = overlayText.length;
  const captionFontSize = pickFontSize(captionLength);

  const fadeIn = interpolate(active.localFrame, [0, 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const imageScale = interpolate(active.localFrame, [0, active.sceneFrames], [1.03, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const cardLift = spring({
    frame: active.localFrame,
    fps,
    from: 24,
    to: 0,
    config: {
      damping: 13,
      mass: 0.8,
      stiffness: 95,
    },
  });

  const cardOpacity = interpolate(active.localFrame, [0, 8], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <AbsoluteFill style={{backgroundColor: '#06070a'}}>
      <AbsoluteFill style={{opacity: fadeIn}}>
        {scene.imageSrc ? (
          <Img
            src={scene.imageSrc}
            style={{
              width: '100%',
              height: '100%',
              objectFit: 'cover',
              transform: `scale(${imageScale})`,
            }}
          />
        ) : null}
      </AbsoluteFill>

      <AbsoluteFill
        style={{
          background:
            'linear-gradient(180deg, rgba(8,10,14,0.1) 0%, rgba(8,10,14,0.35) 58%, rgba(8,10,14,0.78) 100%)',
        }}
      />

      <AbsoluteFill
        style={{
          justifyContent: 'flex-end',
          alignItems: 'center',
          paddingBottom: 88,
          paddingHorizontal: 48,
        }}
      >
        <div
          style={{
            width: '90%',
            maxWidth: 920,
            opacity: cardOpacity,
            transform: `translateY(${cardLift}px)`,
            borderRadius: 34,
            padding: '28px 32px',
            background: theme.cardBackground,
            border: theme.cardBorder,
            boxShadow: '0 20px 44px rgba(0,0,0,0.34)',
            outline: '1px solid rgba(255,255,255,0.06)',
          }}
        >
          <div
            style={{
              width: 78,
              height: 5,
              borderRadius: 99,
              marginBottom: 16,
              background: theme.accent,
            }}
          />

          <div
            style={{
              color: theme.textColor,
              fontFamily: theme.fontFamily,
              fontSize: captionFontSize,
              fontWeight: 760,
              lineHeight: 1.08,
              letterSpacing: -1.1,
              textShadow: '0 8px 20px rgba(0,0,0,0.4)',
              whiteSpace: 'pre-wrap',
              wordBreak: 'break-word',
            }}
          >
            {overlayText}
          </div>
        </div>
      </AbsoluteFill>

      {voiceoverAudioSrc ? <Audio src={voiceoverAudioSrc} /> : null}
    </AbsoluteFill>
  );
};
