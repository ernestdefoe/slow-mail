import type Mithril from 'mithril';
import app from 'flarum/forum/app';
import { countryName } from './api';

/*
 * The stamps, the postmark and the envelope: drawn here as SVG, an original
 * set, so nothing is fetched and they look sharp at any size. Colours inside
 * a stamp are the artwork's own; everything that is text outside it takes
 * its colour from the theme (forum.less).
 */

declare const m: Mithril.Static;

let uid = 0;
const nextId = (prefix: string) => `sm-${prefix}-${++uid}`;

type Scene = (country?: string | null) => Mithril.Children;

/** Each scene fills the stamp's 48 × 60 picture area, placed at (6, 6). */
const SCENES: Record<string, Scene> = {
  lighthouse: () => [
    <rect width="48" height="60" fill="#a8d8ea" />,
    <path d="M24 14 L48 4 L48 24 Z" fill="#fff6c2" opacity="0.85" />,
    <rect y="44" width="48" height="16" fill="#2f6f9f" />,
    <path d="M0 46 Q6 43 12 46 T24 46 T36 46 T48 46 V60 H0 Z" fill="#285f8a" />,
    <path d="M19 44 L21 16 H27 L29 44 Z" fill="#fdfdfd" />,
    <path d="M20.2 30 H27.8 L28.3 36 H19.7 Z M21 20 H27 L27.3 24 H20.7 Z" fill="#d64045" />,
    <rect x="20" y="11" width="8" height="5" fill="#3b3b58" />,
    <rect x="21.5" y="12" width="5" height="3" fill="#ffd166" />,
    <path d="M19 11 L24 7 L29 11 Z" fill="#d64045" />,
  ],
  mountain: () => [
    <rect width="48" height="60" fill="#f9dcb0" />,
    <circle cx="33" cy="20" r="8" fill="#f08a4b" />,
    <path d="M-2 60 L16 26 L28 44 L36 34 L52 60 Z" fill="#5b6b8c" />,
    <path d="M16 26 L21 35 L18 33 L15 36 L12.5 32.5 Z" fill="#fff" />,
    <path d="M10 60 L30 38 L50 60 Z" fill="#8a97b4" />,
    <path d="M30 38 L34 42.5 L31 41.5 L29 43 L26.5 42 Z" fill="#fff" />,
  ],
  wave: () => [
    <rect width="48" height="60" fill="#eaf4f4" />,
    <circle cx="36" cy="14" r="5" fill="#e76f51" />,
    <path d="M-2 60 V40 C6 26 22 20 32 28 C38 33 34 42 28 40 C24 39 25 34 29 34 C24 30 14 34 10 46 C18 44 30 48 48 44 V60 Z" fill="#2a7ab0" />,
    <path d="M32 28 C38 33 34 42 28 40 C24 39 25 34 29 34" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" />,
    <path d="M-2 54 Q10 50 20 54 T48 53" fill="none" stroke="#9ed0e6" stroke-width="1.4" />,
  ],
  balloon: () => [
    <rect width="48" height="60" fill="#bfe3f2" />,
    <ellipse cx="10" cy="46" rx="8" ry="3" fill="#fff" />,
    <ellipse cx="38" cy="12" rx="7" ry="2.6" fill="#fff" />,
    <path d="M24 8 C34 8 38 16 37 23 C36 30 29 35 26 39 H22 C19 35 12 30 11 23 C10 16 14 8 24 8 Z" fill="#e4572e" />,
    <path d="M24 8 C28 8 30 16 29.5 23 C29 30 26.5 35 25.5 39 H22.5 C21.5 35 19 30 18.5 23 C18 16 20 8 24 8 Z" fill="#f3a712" />,
    <path d="M22 39 L21.5 44 M26 39 L26.5 44" stroke="#6b4f3a" stroke-width="0.8" />,
    <rect x="20.5" y="44" width="7" height="5" rx="1" fill="#8d6346" />,
  ],
  fox: () => [
    <rect width="48" height="60" fill="#f3e3c3" />,
    <path d="M8 18 L14 6 L19 20 Z M40 18 L34 6 L29 20 Z" fill="#c4612a" />,
    <path d="M11 16 L14 9 L17 18 Z M37 16 L34 9 L31 18 Z" fill="#3b2a20" />,
    <path d="M6 20 C10 14 38 14 42 20 L24 46 Z" fill="#e07a2f" />,
    <path d="M6 20 C12 24 18 30 24 46 L14 34 Z M42 20 C36 24 30 30 24 46 L34 34 Z" fill="#fff8ee" />,
    <circle cx="17" cy="26" r="1.8" fill="#2b1d16" />,
    <circle cx="31" cy="26" r="1.8" fill="#2b1d16" />,
    <ellipse cx="24" cy="44" rx="2.6" ry="2" fill="#2b1d16" />,
    <rect y="52" width="48" height="8" fill="#7a9e6e" />,
  ],
  cactus: () => [
    <rect width="48" height="60" fill="#f7c59f" />,
    <circle cx="12" cy="14" r="6" fill="#fde68a" />,
    <rect y="48" width="48" height="12" fill="#d98c4a" />,
    <path d="M21 50 V20 a3.5 3.5 0 0 1 7 0 V50 Z" fill="#3f8f5a" />,
    <path d="M21 36 H15 a3 3 0 0 1 -3 -3 V27 a2.5 2.5 0 0 1 5 0 V31 H21 Z" fill="#3f8f5a" />,
    <path d="M28 32 H33 V24 a2.5 2.5 0 0 1 5 0 V30 a4 4 0 0 1 -4 4 H28 Z" fill="#3f8f5a" />,
    <path d="M24.5 22 V48" stroke="#2f6e45" stroke-width="0.8" />,
    <circle cx="24.5" cy="17.5" r="2" fill="#f472b6" />,
  ],
  blossom: () => {
    const flower = (x: number, y: number, r: number) => (
      <g>
        {[0, 72, 144, 216, 288].map((a) => (
          <circle cx={x + Math.cos((a * Math.PI) / 180) * r} cy={y + Math.sin((a * Math.PI) / 180) * r} r={r * 0.85} fill="#f28fb0" />
        ))}
        <circle cx={x} cy={y} r={r * 0.6} fill="#fde68a" />
      </g>
    );
    return [
      <rect width="48" height="60" fill="#fbe9ef" />,
      <path d="M-2 50 C12 44 20 34 30 30 C36 28 42 20 50 16" fill="none" stroke="#6b4f3a" stroke-width="2.2" stroke-linecap="round" />,
      <path d="M20 37 C18 30 22 26 24 24" fill="none" stroke="#6b4f3a" stroke-width="1.4" stroke-linecap="round" />,
      flower(24, 23, 3.4),
      flower(36, 27, 3),
      flower(12, 44, 3.2),
      flower(44, 17, 2.6),
      <circle cx="30" cy="44" r="1.4" fill="#f28fb0" />,
      <circle cx="16" cy="18" r="1.1" fill="#f28fb0" />,
    ];
  },
  aurora: () => {
    const id = nextId('aurora');
    return [
      <defs>
        <linearGradient id={id} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#4ade80" />
          <stop offset="1" stop-color="#22d3ee" />
        </linearGradient>
      </defs>,
      <rect width="48" height="60" fill="#1d2b53" />,
      <path d="M-2 26 C10 12 18 30 30 16 C38 8 44 18 50 12 V22 C44 28 38 18 30 26 C18 38 10 22 -2 36 Z" fill={`url(#${id})`} opacity="0.85" />,
      ...[
        [6, 8],
        [20, 6],
        [40, 30],
        [12, 40],
        [34, 6],
      ].map(([x, y]) => <circle cx={x} cy={y} r="0.8" fill="#fff" />),
      <path d="M-2 60 V50 L4 42 L10 50 L15 40 L21 50 L27 44 L33 50 L39 38 L45 50 L50 46 V60 Z" fill="#0b1020" />,
    ];
  },
  owl: () => [
    <rect width="48" height="60" fill="#2e3a59" />,
    <circle cx="38" cy="11" r="5" fill="#f7e08a" />,
    <path d="M4 50 H44" stroke="#6b4f3a" stroke-width="3" stroke-linecap="round" />,
    <path d="M12 22 L14 12 L19 18 H29 L34 12 L36 22 C38 34 34 48 24 48 C14 48 10 34 12 22 Z" fill="#8b5e3c" />,
    <path d="M16 36 C18 44 30 44 32 36 C30 40 18 40 16 36 Z" fill="#c9a27a" />,
    <circle cx="19" cy="26" r="5" fill="#fff" />,
    <circle cx="29" cy="26" r="5" fill="#fff" />,
    <circle cx="19" cy="26" r="2.4" fill="#f59e0b" />,
    <circle cx="29" cy="26" r="2.4" fill="#f59e0b" />,
    <circle cx="19" cy="26" r="1.1" fill="#111" />,
    <circle cx="29" cy="26" r="1.1" fill="#111" />,
    <path d="M22.5 31 L24 34 L25.5 31 Z" fill="#f59e0b" />,
  ],
  teapot: () => [
    <rect width="48" height="60" fill="#e7efe6" />,
    <path
      d="M18 14 C16 10 20 8 18 4 M24 14 C22 10 26 8 24 4 M30 14 C28 10 32 8 30 4"
      fill="none"
      stroke="#9db4a0"
      stroke-width="1.2"
      stroke-linecap="round"
    />,
    <path d="M36 32 C44 28 44 22 40 22" fill="none" stroke="#c0504d" stroke-width="2.4" stroke-linecap="round" />,
    <path d="M12 34 L4 24 L6 23 L15 30 Z" fill="#c0504d" />,
    <ellipse cx="24" cy="36" rx="13" ry="11" fill="#c0504d" />,
    <rect x="15" y="44" width="18" height="3" rx="1.5" fill="#9b3b39" />,
    <path d="M16 26 C18 22 30 22 32 26 Z" fill="#9b3b39" />,
    <circle cx="24" cy="21.5" r="1.8" fill="#9b3b39" />,
    <path d="M17 36 C20 40 28 40 31 36" fill="none" stroke="#f2c6c3" stroke-width="1.2" />,
    <rect y="50" width="48" height="10" fill="#c8d7c4" />,
  ],
  moon: () => [
    <rect width="48" height="60" fill="#26324f" />,
    <path d="M30 12 A16 16 0 1 0 36 42 A13 13 0 1 1 30 12 Z" fill="#f7e08a" />,
    ...[
      [8, 10, 1],
      [40, 18, 0.9],
      [36, 52, 0.8],
      [12, 50, 1.1],
      [42, 34, 0.7],
    ].map(([x, y, r]) => <circle cx={x} cy={y} r={r} fill="#fff" />),
    <path d="M38 8 L39 10 L41 10.5 L39 11 L38 13 L37 11 L35 10.5 L37 10 Z" fill="#fff" />,
  ],
  whale: () => [
    <rect width="48" height="60" fill="#cfe8f3" />,
    <path d="M22 18 C20 12 18 10 16 9 M22 18 C22 12 24 10 26 8" fill="none" stroke="#7fb8d6" stroke-width="1.4" stroke-linecap="round" />,
    <path d="M4 34 C4 24 16 20 26 22 C34 24 38 30 40 34 L46 28 L45 38 L48 44 L40 40 C34 46 12 48 6 42 C4 40 4 37 4 34 Z" fill="#3c6e9e" />,
    <path d="M6 38 C14 44 30 44 38 39 C30 46 12 47 6 42 Z" fill="#dbeaf2" />,
    <circle cx="13" cy="31" r="1.3" fill="#0f2235" />,
    <path d="M-2 52 Q8 48 18 52 T38 52 T50 51 V60 H-2 Z" fill="#7fb8d6" />,
  ],
  home: (country) => [
    <rect width="48" height="60" fill="#fdf2d0" />,
    <circle cx="24" cy="24" r="13" fill="none" stroke="#c8963e" stroke-width="1.2" />,
    <ellipse cx="24" cy="24" rx="6" ry="13" fill="none" stroke="#c8963e" stroke-width="1" />,
    <path d="M11 24 H37 M13 17 H35 M13 31 H35" stroke="#c8963e" stroke-width="0.8" />,
    <text x="24" y="50" text-anchor="middle" font-family="Georgia, serif" font-weight="700" font-size="11" fill="#7c2d12" letter-spacing="1">
      {(country || '').toUpperCase()}
    </text>,
  ],
};

export const STAMPS = Object.keys(SCENES);

/** A perforated stamp. `country` is printed on the "home" stamp. */
export function Stamp(attrs: { name: string; country?: string | null; className?: string; title?: string }): Mithril.Children {
  const id = nextId('perf');
  const scene = SCENES[attrs.name] || SCENES.lighthouse;
  // Perforations: holes every 6 px around a 60 × 72 stamp.
  const holes: Mithril.Children[] = [];
  for (let x = 3; x <= 57; x += 6) holes.push(<circle cx={x} cy="0" r="2.1" />, <circle cx={x} cy="72" r="2.1" />);
  for (let y = 3; y <= 69; y += 6) holes.push(<circle cx="0" cy={y} r="2.1" />, <circle cx="60" cy={y} r="2.1" />);

  return (
    <svg className={'SlowMailStamp ' + (attrs.className || '')} viewBox="0 0 60 72" role="img" aria-label={attrs.title || ''}>
      <defs>
        <mask id={id}>
          <rect width="60" height="72" fill="#fff" />
          <g fill="#000">{holes}</g>
        </mask>
      </defs>
      <g mask={`url(#${id})`}>
        <rect width="60" height="72" fill="#fffdf7" />
        <g transform="translate(6 6)">{scene(attrs.country)}</g>
        <rect x="6" y="6" width="48" height="60" fill="none" stroke="rgba(0,0,0,.15)" stroke-width="0.6" />
      </g>
    </svg>
  );
}

/** The round postmark: where from (a region or a country, never a city) and the day it was sent. */
export function Postmark(attrs: { place: string | null; date: string; forum?: string }): Mithril.Children {
  const id = nextId('ring');
  const place = (attrs.place || attrs.forum || '').toUpperCase();
  const date = new Date(attrs.date);
  const day = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { month: 'short', day: 'numeric' }).format(date).toUpperCase();

  return (
    <svg className="SlowMailPostmark" viewBox="0 0 120 64" aria-hidden="true">
      <g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
        <path d="M2 18 Q10 14 18 18 T34 18 T50 18" />
        <path d="M2 28 Q10 24 18 28 T34 28 T50 28" />
        <path d="M2 38 Q10 34 18 38 T34 38 T50 38" />
        <path d="M2 48 Q10 44 18 48 T34 48 T50 48" />
        <circle cx="86" cy="32" r="29" />
        <circle cx="86" cy="32" r="18" stroke-width="1" />
      </g>
      <defs>
        <path id={id} d="M 62.5 32 A 23.5 23.5 0 1 1 62.5 32.1" />
      </defs>
      <text font-size={place.length > 22 ? 5.6 : 7} font-family="Georgia, serif" font-weight="700" fill="currentColor" letter-spacing="1">
        <textPath href={`#${id}`} startOffset="2%">
          {place}
        </textPath>
      </text>
      <text x="86" y="31" text-anchor="middle" font-size="6.6" font-family="Georgia, serif" font-weight="700" fill="currentColor">
        {day}
      </text>
      <text x="86" y="39.5" text-anchor="middle" font-size="6" font-family="Georgia, serif" fill="currentColor">
        {date.getFullYear()}
      </text>
    </svg>
  );
}

export function originLabel(postmark: string | null, origin: string | null): string | null {
  return postmark || countryName(origin);
}
