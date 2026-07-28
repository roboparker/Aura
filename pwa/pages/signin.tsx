import Head from "next/head";
import AuthCard from "@/components/auth/AuthCard";
import { pageTitle } from "@/lib/pageTitle";

const SignIn = () => (
  <>
    <Head>
      <title>{pageTitle("Sign In")}</title>
    </Head>
    <AuthCard defaultTab="signin" />
  </>
);

export default SignIn;
