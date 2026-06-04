# Product update - December 2025

North star (If a viewer remembers only one thing from this video, what should it be?):
- DuoAsset is the one place to accurately manage your entire crypto portfolio and provide annual, accurate reporting
  for Canadian tax returns.

## Outline

- Introduction
- Import CSV files into stage
- Staging and validation
- Final import
- Wallets and transactions
- ACB calculation and reporting
- Roadmap and next steps
- Wrap up

## Opening Hook

** Full Camera **

Most crypto portfolios are disorganized.

You have a variety of exchanges and wallets, and nothing to clearly show what you own, what you did over the past year,
and how much tax you may have to pay.

Today I will show you what I'm building to solve that.

## Introduction

** Full Camera **

If you're a serious investor, you have at least one exchange and one self-custody wallet.

But more likely, you also have an on or off-ramp, maybe another exchange or two, and a selection of self-custody wallets.

In today's video, I will show you how to keep track of all that.

The product that I am developing is called DuoAsset, and it is a companion product to DuoLedger which I am also developing.

It is aimed at individuals tracking crypto portfolios who care about accuracy, especially adjusted cost basis,
or ACB, and accurate reporting for Canadian tax returns.

Today we will walk through the setup, and then I'll give you a quick tour to show what we have developed so far.

## Begin Demo

** Screen share with inset **

** Show dashboard, already logged in **

This is DuoAsset, and I am currently logged in and looking at the main dashboard.

Although there is a lot more to come, this is where you will be able to see a high-level summary of your entire portfolio.

Next, we will move over to the import page.

## Import CSV files into stage

** Click on Import in the main menu **

You will notice a list of CSV files which have already been set up for import.

In an upcoming version, you will be able to add and remove files from the list, but for now it's set up for the demo.

CSV files are the best way, for now, to import your data.

Some similar programs will scan the blockchain to import your transactions, and while this is very convenient,
it requires support for each type of blockchain.

Also, for privacy coins which are gaining in popularity, you cannot import data from the blockchain.

That would defeat their purpose.

So, for now we will rely on CSV files, but in future we will be looking at ways to get this data directly from your
exchange using an API.

Let's run the import.

** Click on the import button **

You can see from the green message that it completed successfully.

## Staging and validation

** Click on Stage in the main menu **

This is the import stage. It is where you review and match transactions before final import.

Right now it's the most colorful page on the site, but the reason for the color-coding is so that you can easily discern
the status of each transaction.

The transactions are sorted by date and time, and you can see the type, description, wallet and the amount.

** Click on the first status badge **

You can click on a status badge and manually change it to another status.

So let's talk about matching.

The idea here is that transactions from different wallets connect together in many cases.

For example, you may transfer crypto from one wallet to another, and you can see this in the import stage.

** Click on page 4, focus on transaction #68 **

Here you will see a transfer of ETH from one wallet to another.

You can see by the time that it arrived one minute later, and you can see that the amounts are identical.

This is an example of a matching transaction, which is actually a transfer.

So there are a few things we can discuss here.

First we will talk about transaction type.

We have: Send, Receive, Trade and Transfer.

In DuoAsset, they are defined as follows:
- Send is when assets are leaving your ownership. For example, you use crypto to buy a subscription.
- Receive is when assets are coming into your ownership. This might be from your bank or a friend sending you crypto.
- Trade is when you sell one asset to buy another.
- All of those events are tax-relevant, meaning they need to be taken into consideration when preparing a tax return.
- Transfer is simply moving an asset from one wallet to another. It is not a taxable event, except that fees can be
  used when preparing your taxes.

Since our goal is to match transactions, let me explain the status badges.
- For the most part, they are self-explanatory. For example, matched and unmatched speak for themselves.
- Auto matched is used when a match was made automatically by the system. We'll see that in a few minutes.
- By contrast, manual is when you match two transactions yourself.
- External is used to mark transactions that you know are either to or from an external wallet.
- Ignored is used to mark a transaction that you don't wish to import.
- And finally, error would be used in case of an internal error.

Now that we've covered transaction types and status badges, let's run auto-match.

** Press the Auto Match button **

Notice how it has auto matched the transaction we were looking at earlier.

** Click the 'matched' link for #68 **

By clicking the matched link, you can see the matching transaction.

** Click the 'matched' link for #24 **

And there's another matched transaction.

** Click the link again to cancel **

Let's look at another example.

** Click on page 3 **

Here we can see a transaction that was automatically flagged as external, because it's a transfer of Canadian
dollars from a bank.

We can also see some pre-matched transactions, which are trades inside an exchange.

** Full camera **

So that was a brief tour of the import stage, which is an important part of the process.

It allows you to import a set of transactions and perform matching before actually committing them to your portfolio.

This is a real differentiator for DuoAsset, the way it allows you to review and match your incoming transactions.

Next we will complete the import.

** Screen share with inset **

## Final import

Before I run the import, I want to explain what is about to happen.

As soon as the import finishes, it will launch a background task to perform valuations.

It happens pretty quickly, so watch for it.

To complete the import, let's press the button now.

** Press the import button **

And there we have it - the transactions have been imported and the stage is empty.

** Quickly - click on Dashboard in the main menu **

I wanted to show you this feature, which is on the dashboard.

This gauge is showing the valuation status as it works in the background.

When the import completed, it launched a background task to perform valuations.

A valuation is the determination of the Canadian Dollar value of every debit, credit and fee.

DuoAsset supports the concept of "reporting currency", which the currency that you report in.

Since I'm in Canada, my reporting currency is the Canadian Dollar.

We do plan to support the US Dollar in a future version, and possibly other fiat currencies as well.

Anyway, let's go look at the transactions that we just imported.

## Wallets and transactions

** Click on Wallets in the main menu **

On this page you can see the wallets.

Wallets are the equivalent to bank accounts.

Each wallet contains only one type of asset, or crypto currency.

In DuoAsset, there are three categories of wallet holders, which are referred to as "platforms".
- There are exchanges where you can buy and sell crypto,
- There are self-custody wallet applications,
- And then there are external wallets, which are used for the "other side" of a send or receive transaction.

** Full camera **

DuoAsset is based on double-entry accounting, so that's why we need external accounts.

Every transaction must have a debit and a credit, so external accounts fill that need.

** Screen share with inset **

Let's look in some wallets and see the transactions.

** Click on ETH under Atomic Wallet **

Here are a few ETH transactions; you can see send and receive...

** Hover over the transfer **

And here is the auto matched transaction that we were looking at in the stage.

You can see that it transfers to the Exodus wallet, whereas everything else goes to the external wallet.

Let's look at this transaction in detail.

** Click on the transaction. Modal dialog appears **

Here are the transaction details.

You can change pretty much anything about this transaction, except the type.

** Close the dialog without saving **

** Click on Wallets in the main menu **

And now let's just see the other side of that transaction.

** Click on the ETH wallet under Exodus **

** Hover over the first transaction **

And here is the other side of that transaction in the Exodus wallet.

** Go back and click on the BTC wallet under Exodus **

Now we'll look at some transactions in the Bitcoin wallet.

You can see here several matched transactions, as well as a number that weren't.

One of the goals of the import stage is to reduce as much as possible, these "external" transactions.

But it's okay too, if they really are inward or outward bound.

We might just want to say what they were for.

** Edit the first external transaction **

Let's say this one was for "Proceeds from computer sale"

** Change the description and save **

And there you can see what this transaction was for.

## ACB calculation and reporting

Now let's look at some reporting features in development.

We'll start with the adjusted cost basis.

** Click on Reports > ACB in the main menu **

Initially there are no ACB calculations, so we need to generate the data.

Let's do that now.

** Press the Build All Assets button **

As we wait, what it's doing is going through all the transactions and calculating the cost basis at each step.

This is a very detailed process, and it takes some time.

Now you can see the results.

In this summary, you can see the amount of each asset that you're currently holding, as well as the total cost
and the average cost per unit.

These costs are in your reporting currency, so we're looking at Canadian dollars here.

We can also get more details for each asset.

** Click on BTC **

Let's look at BTC.

Here you can see 3 different perspectives: The daily ACB, Transaction Events and Capital Gains/Disposals.

Most of this information is useful during development, so it is likely to change.

For example, we can see the events at an atomic level, which make up the cost basis.

** Click on Transaction Events tab **

You can see each taxable event: Acquisitions, disposals and fees.

Transfers are not included, but their fees are.

The real outcome here is the Capital Gains/Disposals. 

** Click on Capital Gains/Disposals tab **

There is actually nothing to show you right now, but when it's ready you're going to see each disposal event
and the associated capital gain or loss.

This is what you will use in your income tax filing.

There is only one other report that I want to show at this time.

** Click on Reports > Transactions in the main menu **

This is the Transaction Report, and it's just a nice summary of every transaction from beginning to end.

You can see that the formatting is intended to give you a lot of useful information in a glance.

## Roadmap and next steps

So what's next?

You can see that there are still a few things that we need to complete before this is ready for general use.

There are two main areas that need a lot of attention:
- First, we need to complete more reporting features so that it can be used when creating a tax return.
- But also we must invest more in the front-end of importing transactions and in providing a useful and visually
  appealing dashboard.

The initial import probably the most difficult part to get right, because each exchange and wallet application has a
different way of encoding the information.

As it is now, each type of CSV file requires a custom "mapper", written in the PHP programming language.

I am looking at ways to allow users to create mapping using a graphical editor, but another alternative is
to tap the open source market and allow other programmers to contribute with their own solutions.

Other than those two major areas, there are lots of opportunities to tune the interface and make it easier
to do matching, editing, and entering new transactions.

Also, a little further down the road, we'd like to look at custom APIs so that we can connect directly to
your exchange and pull data.

## Wrap up

So that’s a look at where DuoAsset is today.

The goal here is simple: give you one place where your crypto activity is accurately tracked, clearly understood, and
ready when you need to prepare a tax return.

This is still early, and there’s more work to do—especially around reporting and import flexibility—but the foundation
is now in place.

If this is the kind of tool you’ve been looking for, I’ll be sharing more updates as development continues.

Thanks for watching, and I’ll see you in the next update.
