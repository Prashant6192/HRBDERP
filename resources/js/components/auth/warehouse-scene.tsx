/**
 * The finished-goods store at dusk: stacked cartons under a warm light,
 * drawn rather than photographed so the sign-in screen carries no image
 * to download and nothing to license.
 */

/** Cell geometry. The grid bleeds past both edges so it reads as a crop. */
const CELL = { w: 58, h: 44, strideX: 65, strideY: 51, x0: -22, bottom: 770 };

/**
 * Muted crate tones, mostly cool and dark so the warm light does the
 * colouring. Two of the eight are warm; any more and the stack reads as
 * confetti rather than a yard at dusk.
 */
const TONES = [
    '#17323f',
    '#1e2d3f',
    '#27505c',
    '#8a3f2c',
    '#152833',
    '#b4702a',
    '#223c48',
    '#2f4a52',
];

/**
 * How many crates are stacked in each column, left to right. Hand-set so
 * the top edge is uneven the way a real stack is — not a rising bar
 * chart — and so it renders identically every time.
 */
const HEIGHTS = [7, 9, 6, 10, 8, 5, 9, 11, 7, 10, 6];

/** Deterministic tone per crate: a stack is mixed, but never random. */
const toneFor = (col: number, row: number) =>
    TONES[(col * 5 + row * 3 + ((col * row) % 4)) % TONES.length];

function Crates() {
    const crates = [];

    for (let col = 0; col < HEIGHTS.length; col++) {
        const x = CELL.x0 + col * CELL.strideX;

        for (let row = 0; row < HEIGHTS[col]; row++) {
            const y = CELL.bottom - (row + 1) * CELL.strideY;

            crates.push(
                <g key={`${col}-${row}`}>
                    <rect
                        x={x}
                        y={y}
                        width={CELL.w}
                        height={CELL.h}
                        rx="5"
                        fill={toneFor(col, row)}
                        stroke="#0a1620"
                        strokeWidth="1.5"
                    />
                    {/* A lit top edge, so the crates read as solid. */}
                    <rect
                        x={x + 4}
                        y={y + 4}
                        width={CELL.w - 8}
                        height="3"
                        rx="1.5"
                        fill="#ffffff"
                        opacity="0.14"
                    />
                </g>,
            );
        }
    }

    return <>{crates}</>;
}

export function WarehouseScene({ className }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 640 900"
            preserveAspectRatio="xMidYMid slice"
            aria-hidden
            focusable="false"
        >
            <defs>
                <linearGradient id="ws-sky" x1="0" y1="0" x2="0.3" y2="1">
                    <stop offset="0%" stopColor="#0a1c26" />
                    <stop offset="55%" stopColor="#123040" />
                    <stop offset="100%" stopColor="#0d1f2b" />
                </linearGradient>
                <radialGradient id="ws-warm" cx="0.24" cy="0.66" r="0.56">
                    <stop offset="0%" stopColor="#ffab4a" stopOpacity="0.44" />
                    <stop offset="50%" stopColor="#e2711d" stopOpacity="0.18" />
                    <stop offset="100%" stopColor="#e2711d" stopOpacity="0" />
                </radialGradient>
                <radialGradient id="ws-cool" cx="0.86" cy="0.1" r="0.6">
                    <stop offset="0%" stopColor="#5ad7e8" stopOpacity="0.26" />
                    <stop offset="100%" stopColor="#5ad7e8" stopOpacity="0" />
                </radialGradient>
                <linearGradient id="ws-shaft" x1="0" y1="0" x2="0.6" y2="1">
                    <stop offset="0%" stopColor="#ffd9a0" stopOpacity="0.15" />
                    <stop offset="100%" stopColor="#ffd9a0" stopOpacity="0" />
                </linearGradient>
                <linearGradient id="ws-floor" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#2b4250" />
                    <stop offset="100%" stopColor="#0a1620" />
                </linearGradient>
                <linearGradient id="ws-fade" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#060f16" stopOpacity="0" />
                    <stop offset="55%" stopColor="#060f16" stopOpacity="0.74" />
                    <stop
                        offset="100%"
                        stopColor="#060f16"
                        stopOpacity="0.97"
                    />
                </linearGradient>
                <linearGradient id="ws-top" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#060f16" stopOpacity="0.5" />
                    <stop offset="100%" stopColor="#060f16" stopOpacity="0" />
                </linearGradient>
                <filter id="ws-grain">
                    <feTurbulence
                        type="fractalNoise"
                        baseFrequency="0.9"
                        numOctaves="3"
                    />
                    <feColorMatrix type="saturate" values="0" />
                </filter>
                <filter
                    id="ws-soften"
                    x="-30%"
                    y="-30%"
                    width="160%"
                    height="160%"
                >
                    <feGaussianBlur stdDeviation="12" />
                </filter>
            </defs>

            <rect width="640" height="900" fill="url(#ws-sky)" />
            <rect width="640" height="900" fill="url(#ws-cool)" />

            {/* Light falling across the stack from the high left. */}
            <g filter="url(#ws-soften)" opacity="0.55">
                <polygon
                    points="-40,0 250,0 40,900 -120,900"
                    fill="url(#ws-shaft)"
                />
                <polygon
                    points="300,0 430,0 260,900 120,900"
                    fill="url(#ws-shaft)"
                />
            </g>

            <Crates />

            {/* The floor the stack stands on. */}
            <path
                d="M-40 768 L680 754 L680 900 L-40 900 Z"
                fill="url(#ws-floor)"
            />
            <path
                d="M-40 768 L680 754 L680 761 L-40 775 Z"
                fill="#8fd2e0"
                opacity="0.22"
            />

            {/* The warm wash, over everything it touches. */}
            <rect width="640" height="900" fill="url(#ws-warm)" />
            <rect width="640" height="230" fill="url(#ws-top)" />
            <rect y="430" width="640" height="470" fill="url(#ws-fade)" />
            <rect
                width="640"
                height="900"
                filter="url(#ws-grain)"
                opacity="0.06"
            />
        </svg>
    );
}
