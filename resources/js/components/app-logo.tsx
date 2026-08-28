import AppLogoIcon from './app-logo-icon';
import AppWordmark from './app-wordmark';

/** The mark and the wordmark together, as the sidebar and the header show them. */
export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="size-8 shrink-0" />
            <div className="ms-1 grid flex-1 text-start">
                <AppWordmark className="h-4" />
            </div>
        </>
    );
}
