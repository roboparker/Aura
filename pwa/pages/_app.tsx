import "@blocknote/core/fonts/inter.css"
import "@blocknote/mantine/style.css"
import "../styles/globals.css"
import Script from "next/script"
import Layout from "../components/common/Layout"
import { UMAMI_SCRIPT_SRC, UMAMI_WEBSITE_ID } from "../lib/analytics"
import type { AppProps } from "next/app"
import type { DehydratedState } from "@tanstack/react-query"

function MyApp({ Component, pageProps }: AppProps<{dehydratedState: DehydratedState}>) {
  return <>
    {/* Self-hosted Umami analytics — only rendered when a website ID is
        configured (never in dev by default). Cookieless; honors DNT. */}
    {UMAMI_WEBSITE_ID && (
      <Script
        src={UMAMI_SCRIPT_SRC}
        data-website-id={UMAMI_WEBSITE_ID}
        data-do-not-track="true"
        strategy="afterInteractive"
      />
    )}
    <Layout dehydratedState={pageProps.dehydratedState}>
      <Component {...pageProps} />
    </Layout>
  </>
}

export default MyApp
