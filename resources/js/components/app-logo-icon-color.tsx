import type { SVGAttributes } from 'react';

export default function AppLogoIconColor(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="4" width="192" height="192" rx="46" fill="#171512" />
            <circle cx="100" cy="80" r="30" fill="#e6b84c" />
            <path
                d="M100 106 L100 158"
                stroke="#e6b84c"
                strokeWidth="20"
                strokeLinecap="round"
            />
            <path
                d="M100 158 L128 158"
                stroke="#e6b84c"
                strokeWidth="20"
                strokeLinecap="round"
            />
        </svg>
    );
}
