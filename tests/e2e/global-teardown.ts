import { restoreHotFile } from './global-setup';

/** Hand `public/hot` back to whatever dev server was using it. */
export default function globalTeardown(): void {
    restoreHotFile();
}
