import Head from "next/head";
import AuthCard from "@/components/auth/AuthCard";

const SignUp = () => (
  <>
    <Head>
      <title>Sign Up - Aura</title>
    </Head>
    <AuthCard defaultTab="signup" />
  </>
);

export default SignUp;
