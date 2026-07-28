import Head from "next/head";
import AuthCard from "@/components/auth/AuthCard";
import { pageTitle } from "@/lib/pageTitle";

const SignUp = () => (
  <>
    <Head>
      <title>{pageTitle("Sign Up")}</title>
    </Head>
    <AuthCard defaultTab="signup" />
  </>
);

export default SignUp;
