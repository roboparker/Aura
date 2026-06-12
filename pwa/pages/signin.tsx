import Head from "next/head";
import AuthCard from "@/components/auth/AuthCard";

const SignIn = () => (
  <>
    <Head>
      <title>Sign In - Madori</title>
    </Head>
    <AuthCard defaultTab="signin" />
  </>
);

export default SignIn;
