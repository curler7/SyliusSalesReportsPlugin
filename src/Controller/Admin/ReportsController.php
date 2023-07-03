<?php

/*
 * This file is part of Monsieur Biz' Sales Reports plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Controller\Admin;

use MonsieurBiz\SyliusSalesReportsPlugin\Event\CustomReportEvent;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\InvalidDateException;
use MonsieurBiz\SyliusSalesReportsPlugin\Form\Type\DateType;
use MonsieurBiz\SyliusSalesReportsPlugin\Form\Type\PeriodType;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

final class ReportsController extends AbstractController
{
    public const APPEND_REPORTS_EVENT = 'monsieurbiz.sylius_sales_report.append_reports';

    /**
     * @var ReportRepository
     */
    protected $reportRepository;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * ReportsController constructor.
     */
    public function __construct(
        ReportRepository $reportRepository,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->reportRepository = $reportRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * View the report for a single date.
     */
    public function indexAction(Request $request): Response
    {
        $form = $this->createForm(DateType::class);
        $formPeriod = $this->createForm(PeriodType::class);

        $form->handleRequest($request);
        $formPeriod->handleRequest($request);

        if (!$form->isSubmitted() && !$formPeriod->isSubmitted()) {
            return $this->render('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
                'form_period' => $formPeriod->createView(),
            ]);
        }
        if (($form->isSubmitted() && !$form->isValid()) || ($formPeriod->isSubmitted() && !$formPeriod->isValid())) {
            return $this->render('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
                'form_period' => $formPeriod->createView(),
            ]);
        }

        // Assert retrieved data
        $data = $form->getData();
        $isPeriod = false;
        if (!$data) {
            $data = $formPeriod->getData();
            $isPeriod = true;
        }
        $channel = $data['channel'];
        $from = $data['date'] ?? $data['from'];
        $to = $data['date'] ?? $data['to'];

        // Reverse date if from date greater than end date
        if ($from > $to) {
            $tmp = $to;
            $to = $from;
            $from = $tmp;
            $data['from'] = $from;
            $data['to'] = $to;
        }

        Assert::isInstanceOf($channel, ChannelInterface::class);
        Assert::isInstanceOf($from, \DateTimeInterface::class);
        Assert::isInstanceOf($to, \DateTimeInterface::class);

        // Form is valid, we can generate the report
        try {
            $totalSalesResult = $this->reportRepository->getSalesForChannelForDates($channel, $from, $to);
            $averageSalesResult = $this->reportRepository->getAverageSalesForChannelForDates($channel, $from, $to);
            $productSalesResult = $this->reportRepository->getProductSalesForChannelForDates($channel, $from, $to);
            $productVariantSalesResult = $this->reportRepository->getProductVariantSalesForChannelForDates($channel, $from, $to);
            $productOptionSalesResult = $this->reportRepository->getProductOptionSalesForChannelForDates($channel, $from, $to);
            $productOptionValueSalesResult = $this->reportRepository->getProductOptionValueSalesForChannelForDates($channel, $from, $to);
        } catch (InvalidDateException $e) {
            $form->addError(new FormError($e->getMessage()));

            return $this->render('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        $event = new CustomReportEvent($channel, $from, $to);
        $this->eventDispatcher->dispatch($event);

        return $this->render('@MonsieurBizSyliusSalesReportsPlugin/Admin/view.html.twig', [
            'form' => $form->createView(),
            'form_period' => $formPeriod->createView(),
            'from' => $from,
            'to' => $to,
            'channel' => $data['channel'],
            'total_sales_result' => $totalSalesResult,
            'average_sales_result' => $averageSalesResult,
            'product_sales_result' => $productSalesResult,
            'product_variant_sales_result' => $productVariantSalesResult,
            'product_option_sales_result' => $productOptionSalesResult,
            'product_option_value_sales_result' => $productOptionValueSalesResult,
            'is_period' => $isPeriod,
            'custom_reports' => $event->getCustomReports(),
        ]);
    }
}
